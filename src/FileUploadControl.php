<?php

namespace Zet\FileUpload;

use Nette\Application\UI\Form;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\DI\Container as DiContainer;
use Nette\Forms\Container;
use Nette\Forms\Controls\UploadControl;
use Nette\Http\IRequest;
use Nette\Localization\ITranslator;
use Nette\SmartObject;
use Nette\Utils\AssertionException;
use Nette\Utils\Html;
use Nette\Utils\Validators;
use Zet\FileUpload\Model\DefaultFile;
use Zet\FileUpload\Model\IUploadModel;
use Zet\FileUpload\Model\UploadController;

/**
 * Class FileUploadControl
 *
 * @author  Zechy <email@zechy.cz>
 * @package Zet\FileUpload
 */
class FileUploadControl extends UploadControl
{
    use SmartObject;

    /** @var callable[] */
    public $onUpload = [];

    /** @var callable[] */
    public $onRename = [];

    /** @var callable[] */
    public $onDelete = [];

    # --------------------------------------------------------------------
    # Registration
    # --------------------------------------------------------------------
    private const PREFIX = 'jqfu';

    /**
     * @static
     *
     * @param       $systemContainer
     * @param array $configuration
     */
    public static function register(DiContainer $systemContainer, $configuration = [])
    {
        $class = __CLASS__;
        Container::extensionMethod('addFileUpload', static function(
            Form $form, $name, $maxFiles = null, $maxFileSize = null
        ) use ($class, $systemContainer, $configuration) {
            $maxFiles = $maxFiles ?? $configuration->maxFiles;
            $maxFileSize = $maxFileSize ?? $configuration->maxFileSize;

            /** @var FileUploadControl $component */
            $component = new $class($name, $maxFiles, $maxFileSize);
            $component->setContainer($systemContainer);
            $component->setUploadModel($configuration->uploadModel);
            $component->setFileFilter($configuration->fileFilter);
            $component->setRenderer($configuration->renderer);

            if (($configuration->translator ?? null) === null) {
                $translator = $systemContainer->getByType(ITranslator::class, false);
                $component->setTranslator($translator);
            } else {
                $component->setTranslator($configuration->translator);
            }

            $component->setAutoTranslate($configuration->autoTranslate ?? false);
            $component->setMessages((array) ($configuration->messages ?? []));
            $component->setUploadSettings($configuration->uploadSettings ?? []);

            $form->addComponent($component, $name);

            return $component;
        });
    }

    /**
     * Vloží CSS do stránky.
     *
     * @static
     *
     * @param string $basePath
     */
    public static function getHead($basePath)
    {
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/functions.js"></script>';
    }

    /**
     * Vloží skripty do stránky.
     *
     * @static
     *
     * @param string $basePath
     */
    public static function getScripts($basePath)
    {
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/js/vendor/jquery.ui.widget.js"></script>';
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/js/load-image.all.min.js"></script>';
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/js/jquery.fileupload.js"></script>';
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/js/jquery.fileupload-process.js"></script>';
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/js/jquery.fileupload-image.js"></script>';
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/js/jquery.fileupload-video.js"></script>';
        echo '<script type="text/javascript" src="' . $basePath . '/fileupload/controller.js"></script>';
    }

    # --------------------------------------------------------------------
    # Control definition
    # --------------------------------------------------------------------
    /**
     * Povolí nahrávat pouze obrázky png, jpeg, jpg, gif.
     *
     * @var string
     */
    private const FILTER_IMAGES = 'Zet\FileUpload\Filter\ImageFilter';

    /**
     * Povolí nahrávat pouze dokumenty typu txt, doc, docx, xls, xlsx, ppt, pptx, pdf.
     *
     * @var string
     */
    private const FILTER_DOCUMENTS = 'Zet\FileUpload\Filter\DocumentFilter';

    /**
     * Povolí nahrávat soubory zip, tar, rar, 7z.
     *
     * @var string
     */
    private const FILTER_ARCHIVE = 'Zet\FileUpload\Filter\ArchiveFilter';

    /**
     * Povolí nahrávat pouze soubory mp3, ogg, aiff.
     *
     * @var string
     */
    private const FILTER_AUDIO = 'Zet\FileUpload\Filter\AudioFilter';

    /**
     * @var DiContainer
     */
    private $container;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var int
     */
    private $maxFiles;

    /**
     * @var int
     */
    private $maxFileSize;

    /**
     * @var string
     */
    private $fileSizeString;

    /**
     * @var UploadController
     */
    private $controller;

    /**
     * @var string
     */
    private $uploadModel;

    /**
     * Třída pro filtrování nahrávaných souborů.
     *
     * @var string
     */
    private $fileFilter;

    /**
     * Pole vlastních definovaných parametrů.
     *
     * @var array
     */
    private $params = [];

    /**
     * @var string
     */
    private $renderer;

    /**
     * @var string
     */
    private $token;

    /**
     * @var DefaultFile[]
     */
    private $defaultFiles = [];

    /**
     * Seznam chybových hlášek.
     * Chyby uploaderu:
     * - maxFiles
     * - maxSize
     * - fileTypes
     *
     * Chyby v PHP:
     * - fileSize
     * - partialUpload
     * - noFile
     * - tmpFolder
     * - cannotWrite
     * - stopped
     *
     * @var string[]
     */
    private $messages = [];

    /**
     * Automaticky překládat všechny chybové zprávy?
     *
     * @var bool
     */
    private $autoTranslate = false;

    /**
     * Pole vlastních hodnot pro konfiguraci uploaderu.
     *
     * @var array
     */
    private $uploadSettings = [];

    /**
     * FileUploadControl constructor.
     *
     * @param string $name Název inputu.
     * @param int $maxFiles Maximální počet souborů.
     * @param string $maxFileSize Maximální velikosti souboru.
     */
    public function __construct($name, $maxFiles, $maxFileSize = null)
    {
        parent::__construct($name);

        $this->maxFiles = $maxFiles;
        if ($maxFileSize === null) {
            $this->fileSizeString = ini_get('upload_max_filesize') . 'B';
            $this->maxFileSize = $this->parseIniSize(ini_get('upload_max_filesize'));
        } else {
            $this->fileSizeString = $maxFileSize . 'B';
            $this->maxFileSize = $this->parseIniSize($maxFileSize);
        }

        $this->controller = new Model\UploadController($this);
        $this->monitor(\Nette\Forms\Form::class, function(\Nette\Forms\Form $form): void {
            $form->addComponent($this->controller, 'uploadController' . ucfirst($this->name));
        });

        $this->token = uniqid(self::PREFIX, true);
    }

    /**
     * Ověření nastavených direktiv, zda nepřekročují nastavení serveru.
     *
     * @throws InvalidValueException
     */
    private function checkSettings(): void
    {
        $postMaxSize = $this->parseIniSize($postMaxSizeString = ini_get('post_max_size'));
        $iniMaxFileSize = $this->parseIniSize($iniMaxFileSizeString = ini_get('upload_max_filesize'));

        if ($this->maxFileSize > $postMaxSize) {
            throw new InvalidValueException(
                sprintf(
                    'Nastavení pro maximální velikost souboru je větší, než dovoluje direktiva `post_max_size` (%s).',
                    $postMaxSizeString
                )
            );
        }

        if ($this->maxFileSize > $iniMaxFileSize) {
            throw new InvalidValueException(
                sprintf(
                    'Nastavení pro maximální velikost souboru je větší, než dovoluje direktiva `upload_max_filesize` (%s).',
                    $iniMaxFileSizeString
                )
            );
        }
    }

    # --------------------------------------------------------------------
    # Setters \ Getters
    # --------------------------------------------------------------------
    /**
     * @param DiContainer $container
     *
     * @internal
     */
    public function setContainer(DiContainer $container): void
    {
        $this->container = $container;
        /** @noinspection PhpParamsInspection */
        $this->cache = new Cache($this->container->getByType(IStorage::class));
        /** @noinspection PhpParamsInspection */
        $this->controller->setRequest($container->getByType(IRequest::class));
    }

    /**
     * @return DiContainer
     * @internal
     */
    private function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * @return int
     * @internal
     */
    public function getMaxFiles(): int
    {
        return $this->maxFiles;
    }

    /**
     * @param int $maxFiles
     *
     * @return $this
     */
    public function setMaxFiles(int $maxFiles): self
    {
        $this->maxFiles = $maxFiles;

        return $this;
    }

    /**
     * @return Model\IUploadModel
     * @throws AssertionException
     * @internal
     */
    public function getUploadModel(): Model\IUploadModel
    {
        if ($this->uploadModel === null) {
            return new Model\BaseUploadModel();
        }

        $model = $this->container->getByType($this->uploadModel);

        Validators::assert($model, IUploadModel::class);

        return $model;
    }

    /**
     * @param string $uploadModel
     *
     * @return $this
     */
    public function setUploadModel(string $uploadModel): self
    {
        $this->uploadModel = $uploadModel;

        return $this;
    }

    /**
     * @return int
     * @internal
     */
    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }

    /**
     * @param int $maxFileSize
     *
     * @return $this
     */
    public function setMaxFileSize(int $maxFileSize): self
    {
        $this->maxFileSize = $this->parseIniSize($maxFileSize);

        return $this;
    }

    /**
     * @return Cache
     */
    public function getCache(): Cache
    {
        return $this->cache;
    }

    /**
     * @return string
     * @internal
     */
    public function getFileSizeString(): string
    {
        return $this->fileSizeString;
    }

    /**
     * @return string
     * @internal
     */
    public function getFileFilter(): string
    {
        return $this->fileFilter;
    }

    /**
     * Nastaví třídu pro filtrování nahrávaných souborů.
     *
     * @param string $fileFilter
     *
     * @return $this
     */
    public function setFileFilter(string $fileFilter): self
    {
        $this->fileFilter = $fileFilter;

        return $this;
    }

    /**
     * Vrátí název pro frontu s tokenem.
     *
     * @param string $token
     *
     * @return string
     * @internal
     */
    public function getTokenizedCacheName(string $token): string
    {
        return $this->getHtmlId() . '-' . $token;
    }

    /**
     * Vrátí identifikační token.
     *
     * @return string
     * @internal
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Nastavení vlastních parametrů k uploadovanému souboru.
     *
     * @param array $params
     *
     * @return FileUploadControl
     */
    public function setParams(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param string $renderer
     *
     * @return FileUploadControl
     */
    public function setRenderer($renderer): FileUploadControl
    {
        $this->renderer = $renderer;

        return $this;
    }

    /**
     * @return string
     */
    public function getRenderer(): string
    {
        return $this->renderer;
    }

    /**
     * @return DefaultFile[]
     */
    public function getDefaultFiles(): array
    {
        return $this->defaultFiles;
    }

    /**
     * @param DefaultFile[] $defaultFiles
     *
     * @return FileUploadControl
     */
    public function setDefaultFiles(array $defaultFiles): self
    {
        $this->defaultFiles = $defaultFiles;

        return $this;
    }

    /**
     * @param DefaultFile $defaultFile
     *
     * @return FileUploadControl
     */
    public function addDefaultFile(DefaultFile $defaultFile): self
    {
        $this->defaultFiles[] = $defaultFile;

        return $this;
    }

    /**
     * @param string[] $messages
     *
     * @return FileUploadControl
     */
    public function setMessages(array $messages): self
    {
        $this->messages = $messages;

        return $this;
    }

    /**
     * @param string $index
     * @param string $message
     *
     * @return FileUploadControl
     */
    public function setMessage($index, $message): self
    {
        $this->messages[$index] = $message;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @return bool
     */
    public function isAutoTranslate(): bool
    {
        return $this->autoTranslate;
    }

    /**
     * @param bool $autoTranslate
     *
     * @return FileUploadControl
     */
    public function setAutoTranslate(bool $autoTranslate): self
    {
        $this->autoTranslate = $autoTranslate;

        return $this;
    }

    /**
     * @return array
     */
    public function getUploadSettings(): array
    {
        return $this->uploadSettings;
    }

    /**
     * @param array $uploadSettings
     *
     * @return FileUploadControl
     */
    public function setUploadSettings(array $uploadSettings): self
    {
        $this->uploadSettings = $uploadSettings;

        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @return FileUploadControl
     */
    public function addUploadSettings(string $name, $value): self
    {
        $this->uploadSettings[$name] = $value;

        return $this;
    }

    # --------------------------------------------------------------------
    # Methods
    # --------------------------------------------------------------------
    /**
     * Získání identifikačního tokenu.
     */
    public function loadHttpData(): void
    {
        parent::loadHttpData();

        /** @var IRequest $request */
        $request = $this->getContainer()->getByType(IRequest::class);
        $this->token = $request->getPost($this->getHtmlName() . '-token');
    }

    /**
     * @return Html
     * @throws InvalidValueException
     */
    public function getControl(): Html
    {
        $this->checkSettings();

        $this->setOption('rendered', true);

        $container = Html::el("div class='zet-fileupload-container'");
        $container->id = $this->getHtmlId() . '-container';

        $token = Html::el("input type='hidden' value='" . $this->token . "'");
        $token->addAttributes(['name' => $this->getHtmlName() . '-token']);

        $container->addHtml($token);
        $container->addHtml($this->controller->getJavaScriptTemplate());
        $container->addHtml($this->controller->getControlTemplate());

        return $container;
    }

    /**
     * Vrátí nacachované hodnoty z controlleru.
     *
     * @return mixed|NULL
     */
    public function getValue()
    {
        return $this->cache->load($this->getTokenizedCacheName($this->token));
    }

    /**
     * Delete cache
     */
    public function __destruct()
    {
        $this->cache->remove($this->getTokenizedCacheName($this->token));
    }

    /**
     * Parses ini size
     *
     * @param string $value
     *
     * @return int
     */
    private function parseIniSize($value): int
    {
        $units = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];
        $unit = strtolower(substr($value, -1));
        if (is_numeric($unit) || !isset($units[$unit])) {
            return $value;
        }

        return ((int) $value) * $units[$unit];
    }
}
