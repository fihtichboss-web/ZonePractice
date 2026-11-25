<?php
// Собирает плагин в файл ZonePractice.phar
// Запуск: ./bin/php7/bin/php -dphar.readonly=0 build.php

$root = __DIR__;
$out = $root . "/ZonePractice.phar";
if (file_exists($out)) {
	unlink($out);
}

$phar = new Phar($out);
$phar->startBuffering();
$phar->addFile($root . "/plugin.yml", "plugin.yml");

foreach (["src", "resources"] as $dir) {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root . "/" . $dir, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($it as $file) {
		$rel = str_replace("\\", "/", substr($file->getPathname(), strlen($root) + 1));
		$phar->addFile($file->getPathname(), $rel);
	}
}

$phar->setStub('<?php __HALT_COMPILER();');
$phar->stopBuffering();
echo "Готово: $out\n";
