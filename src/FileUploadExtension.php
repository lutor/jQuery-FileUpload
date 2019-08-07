<?php

namespace Zet\FileUpload;

use Nette\DI\Container;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * Class FileUploadExtension
 *
 * @author Zechy <email@zechy.cz>
 * @package Zet\FileUpload
 */
final class FileUploadExtension extends \Nette\DI\CompilerExtension
{
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'maxFiles' => Expect::int(25),
            'maxFileSize' => Expect::int(),
            'uploadModel' => Expect::string(),
            'fileFilter' => Expect::string(),
            'renderer' => Expect::string(),
            'translator' => Expect::string(),
            'autoTranslate' => Expect::bool(false),
            'messages' => Expect::structure([
                "maxFiles" => Expect::string("Maximální počet souborů je {maxFiles}."),
                "maxSize" => Expect::string("Maximální velikost souboru je {maxSize}."),
                "fileTypes" => Expect::string("Povolené typy souborů jsou {fileTypes}."),
                "fileSize" => Expect::string("Soubor je příliš veliký."),
                "partialUpload" => Expect::string("Soubor byl nahrán pouze částěčně."),
                "noFile" => Expect::string("Nebyl nahrán žádný soubor."),
                "tmpFolder" => Expect::string("Chybí dočasná složka."),
                "cannotWrite" => Expect::string("Nepodařilo se zapsat soubor na disk."),
                "stopped" => Expect::string("Nahrávání souboru bylo přerušeno."),
            ]),
            'uploadSettings' => Expect::array(),
        ]);
    }

    /**
     * @param \Nette\PhpGenerator\ClassType $class
     */
    public function afterCompile(\Nette\PhpGenerator\ClassType $class)
    {
        $init = $class->methods['initialize'];

        $init->addBody('\Zet\FileUpload\FileUploadControl::register($this->getService(?), ?);', [
            $this->getContainerBuilder()->getByType(Container::class), $this->getConfig(),
        ]);
    }
}
