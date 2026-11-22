<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

/** Server-side OpenAI Responses API client. Fixed origin, TLS verification, no redirects or secret logging. */
final class OpenAIClient
{
    private const ORIGIN='https://api.openai.com';
    private string $key;
    /** @param ?callable $driver Injected only by local contract tests. */
    public function __construct(private readonly mixed $driver=null, ?string $key=null)
    {
        $value=$key??getenv('CAJEER_OPENAI_API_KEY');
        if (!is_string($value) || !preg_match('/^sk-[A-Za-z0-9_\-]{12,512}$/D',$value)) {
            throw new \RuntimeException('OpenAI API key is not configured');
        }
        $this->key=$value;
    }

    public static function configured(): bool
    {
        $key=getenv('CAJEER_OPENAI_API_KEY');
        return is_string($key) && preg_match('/^sk-[A-Za-z0-9_\-]{12,512}$/D',$key)===1;
    }

    /** Probe actual model IDs available to the configured project, without a paid generation call. */
    public function models(): array
    {
        $json=$this->request('/v1/models','GET',null);
        if (!is_array($json['data']??null)) throw new \RuntimeException('Invalid OpenAI models response');
        $models=[];
        foreach ($json['data'] as $model) {
            $id=$model['id']??null;
            if (is_string($id) && preg_match('/^gpt-[a-z0-9._-]{1,75}$/D',$id)) $models[]=$id;
        }
        sort($models,SORT_STRING);
        return array_slice(array_values(array_unique($models)),0,150);
    }

    public function generate(string $prompt,array $config): array
    {
        AIConfig::validate($config);
        if (!$config['enabled']) throw new \RuntimeException('OpenAI integration is disabled');
        if ($prompt==='' || strlen($prompt)>$config['max_input_chars'] ||
            !preg_match('//u',$prompt) || str_contains($prompt,"\0")) {
            throw new \InvalidArgumentException('Prompt is empty, invalid or exceeds configured limit');
        }
        $payload=['model'=>$config['model'],'input'=>$prompt,
            'max_output_tokens'=>$config['max_output_tokens'],'store'=>false];
        $data=$this->request('/v1/responses','POST',$payload);
        if (($data['status']??null)!=='completed' || !is_array($data['output']??null)) {
            throw new \RuntimeException('OpenAI generation did not complete');
        }
        $text='';
        foreach ($data['output'] as $item) {
            if (!is_array($item) || ($item['type']??null)!=='message' || !is_array($item['content']??null)) continue;
            foreach ($item['content'] as $piece) {
                if (is_array($piece) && ($piece['type']??null)==='output_text' && is_string($piece['text']??null)) {
                    $text.=$piece['text'];
                }
            }
        }
        if ($text==='' || strlen($text)>100000 || !preg_match('//u',$text)) {
            throw new \RuntimeException('OpenAI returned no usable text');
        }
        $usage=$data['usage']??null;
        $in=$usage['input_tokens']??null; $out=$usage['output_tokens']??null;
        if (!is_int($in) || !is_int($out) || $in<0 || $out<0 || $in>1000000 || $out>1000000) {
            throw new \RuntimeException('OpenAI token usage missing or invalid');
        }
        return ['text'=>$text,'model'=>is_string($data['model']??null)?substr($data['model'],0,80):$config['model'],
            'input_tokens'=>$in,'output_tokens'=>$out];
    }

    private function request(string $path,string $method,?array $payload): array
    {
        if (!in_array([$path,$method],[['/v1/models','GET'],['/v1/responses','POST']],true)) {
            throw new \LogicException('Unsupported OpenAI API operation');
        }
        $body=$payload===null?'':json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (strlen($body)>24000) throw new \RuntimeException('OpenAI request exceeds safe size');
        $headers=['Authorization: Bearer '.$this->key,'Accept: application/json','Connection: close'];
        if ($method==='POST') $headers[]='Content-Type: application/json';
        try {
            if ($this->driver!==null) {
                [$status,$raw]=($this->driver)(self::ORIGIN.$path,$method,$headers,$body);
            } else {
                $context=stream_context_create([
                    'http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,
                        'timeout'=>18,'follow_location'=>0,'max_redirects'=>0,
                        'ignore_errors'=>true,'protocol_version'=>1.1],
                    'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],
                ]);
                $stream=@fopen(self::ORIGIN.$path,'rb',false,$context);
                if (!is_resource($stream)) throw new \RuntimeException('OpenAI network or TLS connection failed');
                try {
                    $responseHeaders=stream_get_meta_data($stream)['wrapper_data']??[];
                    $status=0;
                    foreach ($responseHeaders as $line) {
                        if (preg_match('~^HTTP/\S+\s+(\d{3})~',$line,$m)) $status=(int)$m[1];
                    }
                    $raw=stream_get_contents($stream,1048577);
                } finally { fclose($stream); }
            }
        } catch (\Throwable $e) {
            // Do not log or rethrow anything that may contain the Authorization header.
            throw new \RuntimeException('OpenAI network request failed');
        }
        if (!is_int($status) || !is_string($raw) || strlen($raw)>1048576) {
            throw new \RuntimeException('Invalid or oversized OpenAI response');
        }
        if ($status===401 || $status===403) throw new \RuntimeException('OpenAI API credentials or project access rejected');
        if ($status===429) throw new \RuntimeException('OpenAI project rate limit or quota exceeded');
        if ($status<200 || $status>=300) throw new \RuntimeException('OpenAI API returned HTTP '.$status);
        try { $json=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('OpenAI API returned invalid JSON'); }
        if (!is_array($json)) throw new \RuntimeException('OpenAI API response is not an object');
        return $json;
    }
}
