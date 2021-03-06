<?php

declare(strict_types=1);

/*
 * @copyright  trilobit GmbH
 * @author     trilobit GmbH <https://github.com/trilobit-gmbh>
 * @license    LGPL-3.0-or-later
 * @link       http://github.com/trilobit-gmbh/contao-filecontent-bundle
 */

namespace Trilobit\FilecontentBundle\Element;

use Contao\Config;
use Contao\ContentElement;
use Contao\Controller;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\Environment;
use Contao\File;
use Contao\FilesModel;
use Contao\FrontendUser;
use Contao\Image;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;

/**
 * Class ContentFiles.
 */
class ContentFiles extends ContentElement
{
    /**
     * @var string[]
     */
    public static $slug = ['file', 'content'];

    /**
     * @var string[]
     */
    protected $objFiles;

    /**
     * @var string
     */
    protected $strTemplate = 'ce_filecontent';

    /**
     * @return string
     */
    public function generate()
    {
        if ($this->useHomeDir && System::getContainer()->get('contao.security.token_checker')->hasFrontendUser()) {
            $this->import(FrontendUser::class, 'User');

            if ($this->User->assignDir && $this->User->homeDir) {
                $this->multiSRC = [$this->User->homeDir];
            }
        } else {
            $this->multiSRC = StringUtil::deserialize($this->multiSRC);
        }

        if (empty($this->multiSRC) && !\is_array($this->multiSRC)) {
            return '';
        }

        $this->objFiles = FilesModel::findMultipleByUuids($this->multiSRC);

        if (null === $this->objFiles) {
            return '';
        }

        $file = Input::get(self::$slug[0], true);
        $item = Input::get(self::$slug[1], true);

        if (($file || $item) && (!isset($_GET['cid']) || Input::get('cid') === $this->id)) {
            while ($this->objFiles->next()) {
                // download
                if (!empty($file) && ($file === $this->objFiles->path || \dirname($file) === $this->objFiles->path)) {
                    $this->triggerDownload($file);
                }

                // comntent
                if (!empty($item) && ($item === $this->objFiles->path || \dirname($item) === $this->objFiles->path)) {
                    $this->triggerFilecontent($item);
                }
            }

            if (!empty($file) && isset($_GET['cid'])) {
                throw new PageNotFoundException('Invalid file name');
            }

            $this->objFiles->reset();
        }

        return parent::generate();
    }

    /**
     * @throws \Exception
     */
    protected function compile()
    {
        global $objPage;

        $files = [];
        $auxDate = [];

        $objFiles = $this->objFiles;
        $allowedDownload = StringUtil::trimsplit(',', strtolower(Config::get('allowedDownload')));

        while ($objFiles->next()) {
            if (isset($files[$objFiles->path]) || !file_exists(System::getContainer()->getParameter('kernel.project_dir').'/'.$objFiles->path)) {
                continue;
            }

            if ('file' === $objFiles->type) {
                $objFile = new File($objFiles->path);

                if (!\in_array($objFile->extension, $allowedDownload, true) || preg_match('/^meta(_[a-z]{2})?\.txt$/', $objFile->basename)) {
                    continue;
                }

                $arrMeta = $this->getMetaData($objFiles->meta, $objPage->language);

                if (empty($arrMeta)) {
                    if ($this->metaIgnore) {
                        continue;
                    }

                    if (null !== $objPage->rootFallbackLanguage) {
                        $arrMeta = $this->getMetaData($objFiles->meta, $objPage->rootFallbackLanguage);
                    }
                }

                if (!$arrMeta['title']) {
                    $arrMeta['title'] = StringUtil::specialchars($objFile->basename);
                }

                $strHref = Environment::get('request');

                if (isset($_GET[self::$slug[0]])) {
                    $strHref = preg_replace('/(&(amp;)?|\?)'.self::$slug[0].'=[^&]+/', '', $strHref);
                }

                if (isset($_GET['cid'])) {
                    $strHref = preg_replace('/(&(amp;)?|\?)cid=\d+/', '', $strHref);
                }

                $files[$objFiles->path] = [
                    'id' => $objFiles->id,
                    'uuid' => $objFiles->uuid,
                    'name' => $objFile->basename,
                    'title' => StringUtil::specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['download'], $objFile->basename)),
                    'link' => $arrMeta['title'],
                    'caption' => $arrMeta['caption'],
                    'file' => $strHref.(false !== strpos($strHref, '?') ? '&amp;' : '?').self::$slug[0].'='.System::urlEncode($objFiles->path).'&amp;cid='.$this->id,
                    'content' => $strHref.(false !== strpos($strHref, '?') ? '&amp;' : '?').self::$slug[1].'='.System::urlEncode($objFiles->path),
                    'filesize' => $this->getReadableSize($objFile->filesize),
                    'icon' => Image::getPath($objFile->icon),
                    'mime' => $objFile->mime,
                    'meta' => $arrMeta,
                    'extension' => $objFile->extension,
                    'path' => $objFile->dirname,
                ];

                $auxDate[] = $objFile->mtime;
            } else {
                $objSubfiles = FilesModel::findByPid($objFiles->uuid, ['order' => 'name']);

                if (null === $objSubfiles) {
                    continue;
                }

                while ($objSubfiles->next()) {
                    if ('folder' === $objSubfiles->type) {
                        continue;
                    }

                    $objFile = new File($objSubfiles->path);

                    if (!\in_array($objFile->extension, $allowedDownload, true) || preg_match('/^meta(_[a-z]{2})?\.txt$/', $objFile->basename)) {
                        continue;
                    }

                    $arrMeta = $this->getMetaData($objSubfiles->meta, $objPage->language);

                    if (empty($arrMeta)) {
                        if ($this->metaIgnore) {
                            continue;
                        }

                        if (null !== $objPage->rootFallbackLanguage) {
                            $arrMeta = $this->getMetaData($objSubfiles->meta, $objPage->rootFallbackLanguage);
                        }
                    }

                    if (!$arrMeta['title']) {
                        $arrMeta['title'] = StringUtil::specialchars($objFile->basename);
                    }

                    $strHref = Environment::get('request');

                    if (preg_match('/(&(amp;)?|\?)'.self::$slug[0].'=/', $strHref)) {
                        $strHref = preg_replace('/(&(amp;)?|\?)'.self::$slug[0].'=[^&]+/', '', $strHref);
                    }

                    $files[$objSubfiles->path] = [
                        'id' => $objSubfiles->id,
                        'uuid' => $objSubfiles->uuid,
                        'name' => $objFile->basename,
                        'title' => StringUtil::specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['download'], $objFile->basename)),
                        'link' => $arrMeta['title'],
                        'caption' => $arrMeta['caption'],
                        'file' => $strHref.(false !== strpos($strHref, '?') ? '&amp;' : '?').self::$slug[0].'='.System::urlEncode($objSubfiles->path).'&amp;cid='.$this->id,
                        'content' => $strHref.(false !== strpos($strHref, '?') ? '&amp;' : '?').self::$slug[1].'='.System::urlEncode($objSubfiles->path),
                        'filesize' => $this->getReadableSize($objFile->filesize),
                        'icon' => Image::getPath($objFile->icon),
                        'mime' => $objFile->mime,
                        'meta' => $arrMeta,
                        'extension' => $objFile->extension,
                        'path' => $objFile->dirname,
                    ];

                    $auxDate[] = $objFile->mtime;
                }
            }
        }

        switch ($this->sortBy) {
            default:
            case 'name_asc':
                uksort($files, 'basename_natcasecmp');

                break;
            case 'name_desc':
                uksort($files, 'basename_natcasercmp');

                break;
            case 'date_asc':
                array_multisort($files, \SORT_NUMERIC, $auxDate, \SORT_ASC);

                break;
            case 'date_desc':
                array_multisort($files, \SORT_NUMERIC, $auxDate, \SORT_DESC);

                break;
            case 'custom':
                if ($this->orderSRC) {
                    $tmp = StringUtil::deserialize($this->orderSRC);

                    if (!empty($tmp) && \is_array($tmp)) {
                        $arrOrder = array_map(static function () {}, array_flip($tmp));

                        foreach ($files as $k => $v) {
                            if (\array_key_exists($v['uuid'], $arrOrder)) {
                                $arrOrder[$v['uuid']] = $v;
                                unset($files[$k]);
                            }
                        }

                        if (!empty($files)) {
                            $arrOrder = array_merge($arrOrder, array_values($files));
                        }

                        $files = array_values(array_filter($arrOrder));
                        unset($arrOrder);
                    }
                }

                break;
            case 'random':
                shuffle($files);

                break;
        }

        $this->Template->listView = true;

        if (!empty(Input::get(self::$slug[1]))) {
            $this->Template->listView = false;
            $objPage->noSearch = false;
        }

        $this->Template->file = $this->file;
        $this->Template->title = $this->fileTitle;
        $this->Template->content = $this->fileContent;

        $this->Template->files = array_values($files);
    }

    /**
     * @param $file
     */
    protected function triggerDownload($file)
    {
        Controller::sendFileToBrowser($file, (bool) $this->inline);
    }

    /**
     * @param $item
     */
    protected function triggerFilecontent($item)
    {
        $buffer = '';

        $itemFileData = FilesModel::findByPath($item);

        if (isset($GLOBALS['TL_HOOKS']['getFileContent']) && \is_array($GLOBALS['TL_HOOKS']['getFileContent'])) {
            foreach ($GLOBALS['TL_HOOKS']['getFileContent'] as $callback) {
                $this->import($callback[0]);
                $buffer = $this->{$callback[0]}->{$callback[1]}($itemFileData, $buffer);
            }
        }

        // @var PageModel $objPage
        global $objPage;

        $arrMeta = $this->getMetaData($itemFileData->meta, $objPage->language);

        if (empty($arrMeta)) {
            if (null !== $objPage->rootFallbackLanguage) {
                $arrMeta = $this->getMetaData($itemFileData->meta, $objPage->rootFallbackLanguage);
            }
        }

        if (!$arrMeta['title']) {
            $arrMeta['title'] = str_replace('.'.pathinfo($itemFileData->name, \PATHINFO_EXTENSION), '', StringUtil::specialchars($itemFileData->name));
        }

        $objPage->title = $arrMeta['title'];
        $objPage->pageTitle = $arrMeta['title'];

        $this->fileTitle = $arrMeta['title'];
        $this->file = $itemFileData;
        $this->fileContent = (('UTF-8' === mb_detect_encoding($buffer, 'UTF-8, ISO-8859-1', true)) ? $buffer : utf8_encode($buffer));
    }
}
