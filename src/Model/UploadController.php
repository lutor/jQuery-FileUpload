<?php

namespace Zet\FileUpload\Model;

use Exception;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI\Control;
use Nette\Http\FileUpload;
use Nette\Http\Request;
use Nette\InvalidStateException;
use Nette\UnexpectedValueException;
use Nette\Utils\Html;
use Zet\FileUpload\FileUploadControl;
use Zet\FileUpload\Filter\IMimeTypeFilter;
use Zet\FileUpload\InvalidFileException;
use Zet\FileUpload\Template\JavascriptBuilder;
use Zet\FileUpload\Template\Renderer\BaseRenderer;

/**
 * Class UploadController
 *
 * @author  Zechy <email@zechy.cz>
 * @package Zet\FileUpload
 */
class UploadController extends Control {

	/**
	 * @var FileUploadControl
	 */
	private $uploadControl;

	/**
	 * @var Request
	 */
	private $request;

	/**
	 * @var IMimeTypeFilter
	 */
	private $filter;

	/**
	 * @var BaseRenderer
	 */
	private $renderer;

	/**
	 * UploadController constructor.
	 *
	 * @param FileUploadControl $uploadControl
	 */
	public function __construct(FileUploadControl $uploadControl) {
		$this->uploadControl = $uploadControl;
	}

	/**
	 * @param Request $request
	 */
	public function setRequest($request) {
		$this->request = $request;
	}

	/**
	 * @return IMimeTypeFilter|NULL
	 */
	public function getFilter() {
		if($this->filter === null) {
			/** @noinspection PhpInternalEntityUsedInspection */
			$className = $this->uploadControl->getFileFilter();
			if($className !== null) {
				$filterClass = new $className;
				if($filterClass instanceof IMimeTypeFilter) {
					$this->filter = $filterClass;
				} else {
					throw new UnexpectedValueException(
						"Třída pro filtrování souborů neimplementuje rozhraní \\Zet\\FileUpload\\Filter\\IMimeTypeFilter."
					);
				}
			}
		}

		return $this->filter;
	}

	/**
	 * @return FileUploadControl
	 */
	public function getUploadControl() {
		return $this->uploadControl;
	}

	/**
	 * @return BaseRenderer
	 */
	public function getRenderer() {
		if($this->renderer === null) {
			$rendererClass = $this->uploadControl->getRenderer();
			$this->renderer = new $rendererClass($this->uploadControl, $this->uploadControl->getTranslator());

			if(!($this->renderer instanceof BaseRenderer)) {
				throw new InvalidStateException(
					"Renderer musí být instancí třídy `\\Zet\\FileUpload\\Template\\BaseRenderer`."
				);
			}
		}

		return $this->renderer;
	}

	/**
	 * Vytvoření šablony s JavaScriptem pro FileUpload.
	 *
	 * @return string
	 */
	public function getJavaScriptTemplate() {
		$builder = new JavascriptBuilder(
			$this->getRenderer(),
			$this
		);

		return $builder->getJsTemplate();
	}

	/**
	 * Vytvoření šablony s přehledem o uploadu.
	 *
	 * @return Html
	 */
	public function getControlTemplate() {
		return $this->getRenderer()->buildDefaultTemplate();
	}

	/**
	 * Zpracování uploadu souboru.
	 */
	public function handleUpload() {
		$files = $this->request->getFiles();
		$token = $this->request->getPost("token");
		$params = json_decode($this->request->getPost("params"), true);

		/** @var FileUpload $file */
		$file = $files[ $this->uploadControl->getHtmlName() ];
		/** @noinspection PhpInternalEntityUsedInspection */
		$model = $this->uploadControl->getUploadModel();
		$cache = $this->uploadControl->getCache();
		$filter = $this->getFilter();

		try {
			if($filter !== null && !$filter->checkType($file)) {
				throw new InvalidFileException($this->getFilter()->getAllowedTypes());
			}

			if($file->isOk()) {
				$returnData = $model->save($file, $params);
				/** @noinspection PhpInternalEntityUsedInspection */
				$cacheFiles = $cache->load($this->uploadControl->getTokenizedCacheName($token));
				if(empty($cacheFiles)) {
					$cacheFiles = [$this->request->getPost("id") => $returnData];
				} else {
					$cacheFiles[ $this->request->getPost("id") ] = $returnData;
				}
				/** @noinspection PhpInternalEntityUsedInspection */
				$cache->save($this->uploadControl->getTokenizedCacheName($token), $cacheFiles);
				$this->uploadControl->onUpload($returnData, $file);
			}

		} catch(InvalidFileException $e) {
			$this->presenter->sendResponse(new JsonResponse([
				"id" => $this->request->getPost("id"),
				"error" => 100,
				"errorMessage" => $e->getMessage()
			]));

		} catch(Exception $e) {
			$this->presenter->sendResponse(new JsonResponse([
				"id" => $this->request->getPost("id"),
				"error" => 99,
				"errorMessage" => $e->getMessage()
			]));
		}

		$this->presenter->sendResponse(new JsonResponse([
			"id" => $this->request->getPost("id"),
			"error" => $file->getError()
		]));
	}

	/**
	 * Odstraní nahraný soubor.
	 */
	public function handleRemove() {
		$id = $this->request->getQuery("id");
		$token = $this->request->getQuery("token");
		$default = (int) ($this->request->getQuery("default") ?? 0);

		if(0 === $default) {
			$cache = $this->uploadControl->getCache();
			/** @noinspection PhpInternalEntityUsedInspection */
			$cacheFiles = $cache->load($this->uploadControl->getTokenizedCacheName($token));
			if(isset($cacheFiles[ $id ])) {
				/** @noinspection PhpInternalEntityUsedInspection */
				$deletedFile = $cacheFiles[ $id ];
				$this->uploadControl->getUploadModel()->remove($cacheFiles[ $id ]);
				unset($cacheFiles[ $id ]);
				/** @noinspection PhpInternalEntityUsedInspection */
				$cache->save($this->uploadControl->getTokenizedCacheName($token), $cacheFiles);
                $this->uploadControl->onDelete([$deletedFile]); // TODO args?
			}
		} else {
			$files = $this->uploadControl->getDefaultFiles();
            $deletedFiles = [];

			foreach($files as $file) {
				if($file->getIdentifier() == $id) {
				    $deletedFiles[] = $file;
					$file->onDelete($id);
				}

                $deletedFiles && $this->uploadControl->onDelete($deletedFiles); // TODO args?
			}
		}
	}

	/**
	 * Přejmenuje nahraný soubor.
	 */
	public function handleRename() {
		$id = $this->request->getQuery("id");
		$newName = $this->request->getQuery("newName");
		$token = $this->request->getQuery("token");

		$cache = $this->uploadControl->getCache();
		/** @noinspection PhpInternalEntityUsedInspection */
		$cacheFiles = $cache->load($this->uploadControl->getTokenizedCacheName($token));

		if(isset($cacheFiles[ $id ])) {
			/** @noinspection PhpInternalEntityUsedInspection */
			$cacheFiles[ $id ] = $this->uploadControl->getUploadModel()->rename($cacheFiles[ $id ], $newName);
			/** @noinspection PhpInternalEntityUsedInspection */
			$cache->save($this->uploadControl->getTokenizedCacheName($token), $cacheFiles);
			$this->uploadControl->onRename($cacheFiles[ $id ], $newName);
		}
	}

	public function validate() {
		// Nette ^2.3.10 bypass
	}
}
