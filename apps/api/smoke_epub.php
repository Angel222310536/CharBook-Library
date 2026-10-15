<?php
use App\Jobs\ParseChapterImport;
use App\Jobs\RunChapterImport;
use App\Models\{Author,Book,Chapter,ChapterImport,User};
use Illuminate\Support\Facades\{DB,Storage};

fwrite(STDOUT, 'QUEUE='.config('queue.default').PHP_EOL);
$user = User::factory()->create();
$author = Author::create(['name'=>'SMOKE']);
$book = Book::create(['title'=>'SMOKE EPUB','description'=>'t','author_id'=>$author->id,'creator_id'=>$user->id,'access_type'=>'free','language'=>'es','is_published'=>false,'work_format'=>'serie']);
$files = [
  'META-INF/container.xml'=>'<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
  'OEBPS/content.opf'=>'<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="b"><metadata/><manifest><item id="c0" href="ch1.xhtml" media-type="application/xhtml+xml"/><item id="c1" href="ch2.xhtml" media-type="application/xhtml+xml"/><item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></manifest><spine toc="ncx"><itemref idref="c0"/><itemref idref="c1"/></spine></package>',
  'OEBPS/toc.ncx'=>'<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap><navPoint id="n0" playOrder="0"><navLabel><text>Uno</text></navLabel><content src="ch1.xhtml"/></navPoint><navPoint id="n1" playOrder="1"><navLabel><text>Dos</text></navLabel><content src="ch2.xhtml"/></navPoint></navMap></ncx>',
  'OEBPS/ch1.xhtml'=>'<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>x</title></head><body><p>Contenido uno.</p></body></html>',
  'OEBPS/ch2.xhtml'=>'<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>x</title></head><body><p>Contenido dos.</p></body></html>',
];
$tmp = tempnam(sys_get_temp_dir(),'e').'.epub';
$zip = new ZipArchive(); $zip->open($tmp, ZipArchive::CREATE); $zip->addFromString('mimetype','application/epub+zip');
foreach($files as $n=>$c) $zip->addFromString($n,$c); $zip->close();
$epubPath='imports/smoke_'.uniqid().'.epub';
Storage::disk('local')->put($epubPath, file_get_contents($tmp)); @unlink($tmp);
$imp = ChapterImport::create(['book_id'=>$book->id,'user_id'=>$user->id,'status'=>'uploaded','source_name'=>'s.epub','source_ext'=>'epub','source_path'=>$epubPath,'source_size'=>Storage::disk('local')->size($epubPath)]);
ParseChapterImport::dispatchSync($imp->id); $imp->refresh();
fwrite(STDOUT, "PARSE status={$imp->status} total={$imp->total}".PHP_EOL);
$imp->update(['status'=>'importing','options'=>['publish'=>true]]);
RunChapterImport::dispatchSync($imp->id); $imp->refresh();
fwrite(STDOUT, "RUN status={$imp->status} created={$imp->created_count} chapters=".Chapter::where('book_id',$book->id)->count().PHP_EOL);
DB::table('chapters')->where('book_id',$book->id)->delete();
DB::table('chapter_imports')->where('book_id',$book->id)->delete();
DB::table('books')->where('id',$book->id)->delete();
DB::table('authors')->where('id',$author->id)->delete();
DB::table('users')->where('id',$user->id)->delete();
fwrite(STDOUT, "CLEANUP done".PHP_EOL);
