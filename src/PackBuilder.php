<?php
declare(strict_types=1);

namespace Bilbofox\Deploy;

use Nette\Utils\FileInfo;
use Nette\Utils\Finder;
use Nette\Utils\Random;
use ZipArchive;
use RuntimeException;

/**
 * Class for configuration / building packs (archives) of deployed projects
 *
 * @author Michal Kvita <Mikvt@seznam.cz>
 */
class PackBuilder
{
    private array $include = [];
    private array $exclude = [];
    private array $excludeMasks = [];

    private array $emptyDirs = [];

    private array $customFiles = [];


    public function __construct(private readonly string $rootDir)
    {

    }

    public function include(array $subpaths): self
    {
        $this->include += $subpaths;
        return $this;
    }

    public function exclude(array $subpaths): self
    {
        $this->exclude += $subpaths;
        return $this;
    }

    public function excludeMasks(array $masks): self
    {
        $this->excludeMasks += $masks;
        return $this;
    }

    public function addEmptyDirs(array $subpaths): self
    {
        $this->emptyDirs += $subpaths;
        return $this;
    }

    public function addCustomFile(string $subpath, string|callable $content): self
    {
        $this->customFiles[$subpath] = $content;
        return $this;
    }

    // ----------------------------------------------------------------------

    public function getFilesIterator(): iterable
    {
        $files = Finder::findFiles('*')->from($this->rootDir);
        $files->filter(function (FileInfo $file): bool {
            $relativePathname = $file->getRelativePathname();
            $result = true;

            if (!empty($this->include)) {
                $result = false;
                foreach ($this->include as $include) {
                    if (str_starts_with($relativePathname, $include)) {
                        $result = true;
                        break;
                    }
                }
            }
            if (!empty($this->exclude) && $result) {
                foreach ($this->exclude as $exclude) {
                    if (str_starts_with($relativePathname, $exclude)) {
                        $result = false;
                        break;
                    }
                }
            }

            return $result;
        });
        if (!empty($this->excludeMasks)) {
            $files->exclude($this->excludeMasks);
        }

        foreach ($files as $file) {
            yield $file->getRelativePathname() => (string)$file;
        }
    }

    public function buildPack(string $packDir = null): string
    {
        $packDir = $packDir ?? sys_get_temp_dir();

        $zip = new ZipArchive();
        $packFilepath = $packDir . DIRECTORY_SEPARATOR . date('Y-m-d-H-i-s') . '_' . Random::generate(10) . '.zip';
        if ($zip->open($packFilepath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Unable to create zip archive');
        }

        $files = $this->getFilesIterator();
        foreach ($files as $subpath => $path) {
            $zip->addFile($path, $subpath);
        }
        foreach ($this->emptyDirs as $emptyDir) {
            $zip->addEmptyDir($emptyDir);
        }
        foreach ($this->customFiles as $subpath => $customFile) {
            $zip->addFromString($subpath, is_callable($customFile) ? $customFile() : $customFile);
        }

        if (!$zip->close()) {
            throw new RuntimeException('Unable to finish zip archive');
        }

        if (!file_exists($packFilepath)) {
            throw new RuntimeException('Zip archive file does not exist - could be no files were added');
        }

        return $packFilepath;
    }

}