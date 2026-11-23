<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use CajeerEngine\Media\{MediaLibrary,MediaView};
use CajeerEngine\Lite;
function check134(bool $result,string $message): void { if (!$result) throw new RuntimeException($message); }
function rejects134(callable $f,string $message): void {try {$f();}catch(InvalidArgumentException|RuntimeException){return;}throw new RuntimeException('Expected rejection: '.$message);}
function deleteTree134(string $folder): void {
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item) {if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($folder);
}
check134(Lite::VERSION==='1.3.4','version 1.3.4');
$root=sys_get_temp_dir().'/cajeer-media-134-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/storage');
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGOUT9rBwMDAxMDAwMDAAAAOPQE9xgjZwQAAAABJRU5ErkJggg==',true);
try {
    $lib=new MediaLibrary($root);
    $tmp=$root.'/img.png';file_put_contents($tmp,$png);
    $entry=$lib->ingest($tmp,'Тест & "quoted".png');
    check134($entry['mime']==='image/png' && $entry['width']===2 && $entry['height']===2, 'real image dimensions');
    check134($lib->statistics()===['count'=>1,'bytes'=>strlen($png)], 'storage accounting');
    check134($lib->read($entry['id'])['bytes']===$png,'roundtrip media');
    $path=$root.'/storage/media/files/'.$entry['id'].'.png';
    check134((fileperms($path)&0777)===0600,'private file mode');
    check134((fileperms($root.'/storage/media')&0777)===0700,'private directory mode');
    $view=MediaView::render($lib,str_repeat('a',64));
    check134(str_contains($view,'src="/media/'.$entry['id'].'"'),'real media link');
    check134(str_contains($view,'Тест &amp; &quot;quoted&quot;.png'),'escaped filename');
    check134(!str_contains($view,'Тест & "quoted".png'),'no raw HTML');
    $lib->rename($entry['id'],'Новое имя.png');
    check134($lib->listing('Новое')[0]['name']==='Новое имя.png','search and rename');
    check134(count($lib->listing('','document'))===0,'image-only filters');
    $pdf=$root.'/doc.pdf';file_put_contents($pdf,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    $pdfEntry=$lib->ingest($pdf,'Документ.pdf');
    check134($pdfEntry['mime']==='application/pdf' && $lib->read($pdfEntry['id'])!==null,'document upload');
    check134(count($lib->listing('','document'))===1,'document filter');
    // All four supported image types are decoded by the runtime, not trusted from extensions.
    foreach ([
        'gif'=>['image/gif','R0lGODdhAgACAIEAACSRsQAAAAAAAAAAACwAAAAAAgACAAAIBgABCAQQEAA7'],
        'webp'=>['image/webp','UklGRjoAAABXRUJQVlA4IC4AAADQAQCdASoCAAIAAUAmJaACdLoB+AADsAD+4uC/8bshUPt5/+px+Tj8nH9ZQAAA'],
        'jpg'=>['image/jpeg','/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwCnRRRXvHxh/9k='],
    ] as $extension=>[$expected,$encoded]) {
        $sample=$root.'/sample.'.$extension;
        file_put_contents($sample,base64_decode($encoded,true));
        $saved=$lib->ingest($sample,'sample.'.$extension);
        check134($saved['mime']===$expected&&$saved['width']===2&&$saved['height']===2,'actual '.$extension.' image upload');
        $lib->delete($saved['id']);
    }
    // Construct a valid quota-saturated index without allocating 100 MB of test media.
    $indexPath=$root.'/storage/media/index.json';
    $originalIndex=file_get_contents($indexPath);
    $original=json_decode($originalIndex,true,512,JSON_THROW_ON_ERROR);
    foreach (['count'=>250,'bytes'=>13] as $quota=>$number) {
        $synthetic=[];
        for($i=0;$i<$number;$i++) {
            $id=str_pad(dechex($i+1),32,'0',STR_PAD_LEFT);
            $row=$pdfEntry;$row['id']=$id;
            $row['size']=$quota==='bytes'?MediaLibrary::MAX_FILE_BYTES:1;
            $synthetic[$id]=$row;
        }
        \CajeerEngine\Update\OperationFiles::json($indexPath,['version'=>1,'files'=>$synthetic]);
        try {$lib->ingest($tmp,'over-quota.png');throw new RuntimeException('Quota did not block upload');}
        catch (InvalidArgumentException $ex) {
            check134(str_contains($ex->getMessage(),$quota==='count'?'Media limit':'storage limit'),'correct quota failure');
        } finally {file_put_contents($indexPath,$originalIndex);}
    }
    rejects134(fn()=>$lib->ingest($tmp,'script.php'),'extension mismatch');
    rejects134(fn()=>$lib->ingest($tmp,'../evil.png'),'traversal in original name');
    rejects134(fn()=>$lib->rename($entry['id'],'../../evil.php'),'rename traversal');
    rejects134(fn()=>$lib->rename($entry['id'],'fake.pdf'),'rename extension mismatch');
    rejects134(fn()=>$lib->read('../path'),'invalid media id');
    rejects134(fn()=>$lib->ingest($pdf,'doc.png'),'fake image extension');
    rejects134(fn()=>$lib->upload(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp,'name'=>'img.png']),'only HTTP uploads');
    $bad=$root.'/bad.png';file_put_contents($bad,$png.'<?php echo 1;');
    rejects134(fn()=>$lib->ingest($bad,'bad.png'),'append payload');
    $oversized=$root.'/big.png';file_put_contents($oversized,str_repeat('a',MediaLibrary::MAX_FILE_BYTES+1));
    rejects134(fn()=>$lib->ingest($oversized,'big.png'),'file byte limit');
    file_put_contents($path,$png.'evil');
    rejects134(fn()=>$lib->read($entry['id']),'tampered media bytes');
    file_put_contents($path,$png);
    $lib->delete($entry['id']);
    check134($lib->read($entry['id'])===null&&!is_file($path),'delete removes index and bytes');
    $file=$root.'/storage/media/files/'.$pdfEntry['id'].'.pdf';
    unlink($file);symlink($tmp,$file);
    rejects134(fn()=>$lib->read($pdfEntry['id']),'media file symlink');
    $index=$root.'/storage/media/index.json';
    file_put_contents($root.'/bad-index','{}');
    unlink($index);symlink($root.'/bad-index',$index);
    rejects134(fn()=>$lib->listing(),'index symlink');
    echo "Media Library 1.3.4: real uploads, PNG/PDF, safe MIME, quotas, indexing, filters, rename, delete, permissions, XSS and symlink protection OK\n";
} finally {deleteTree134($root);}
