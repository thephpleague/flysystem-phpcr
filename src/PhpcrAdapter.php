<?php

namespace League\Flysystem\Phpcr;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use PHPCR\NodeInterface;
use PHPCR\PathNotFoundException;
use PHPCR\PropertyType;
use PHPCR\SessionInterface;
use PHPCR\Util\NodeHelper;
use PHPCR\Util\PathHelper;

/**
 * Flysystem adapter for the PHP content repository.
 *
 * @author David Buchmann <mail@davidbu.ch>
 */
class PhpcrAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    /**
     * @var SessionInterface
     */
    private $session;

    /**
     * Constructor.
     *
     * @param SessionInterface $session
     * @param string           $root
     */
    public function __construct(SessionInterface $session, $root)
    {
        $this->session = $session;
        if (!$session->nodeExists($root)) {
            $parent = NodeHelper::createPath($this->session, PathHelper::getParentPath($root));
            $parent->addNode(PathHelper::getNodeName($root), 'nt:folder');
        } else {
            $rootNode = $this->session->getNode($root);
            if (!$rootNode->isNodeType('nt:folder')) {
                throw new \LogicException(sprintf(
                    'The root node at %s must be of type nt:folder, found %s',
                    $root ,
                    $rootNode->getPrimaryNodeType()->getName())
                );
            }
        }
        $this->setPathPrefix($root);
    }

    /**
     * Set the path prefix.
     *
     * @param string $prefix
     *
     * @return self
     */
    public function setPathPrefix($prefix)
    {
        parent::setPathPrefix($prefix);
        if (null === $this->pathPrefix) {
            $this->pathPrefix = $this->pathSeparator;
        }
    }

    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix($path)
    {
        $path = ltrim($path, '/');
        if (strlen($path) === 0) {
            return rtrim($this->getPathPrefix(), '/');
        }

        return $this->getPathPrefix() . $path;
    }

    /**
     * Ensure the directory exists and try to create it if it does not exist.
     *
     * @param string  $path path relative to the $root.
     * @param boolean $create Whether to create the folder if not existing.
     *
     * @return NodeInterface The node at $root/$path.
     */
    private function getFolderNode($path, $create = false)
    {
        $location = $this->applyPathPrefix($path);
        if (!$create && !$this->session->nodeExists($location)) {
            // trigger the phpcr exception rather than trying to create parent nodes.
            return $this->session->getNode($location);
        }

        $folders = array();
        while (!$this->session->nodeExists($location)) {
            $folders[] = PathHelper::getNodeName($location);
            $location = PathHelper::getParentPath($location);
        }

        $node = $this->session->getNode($location);
        while (null !== $folder = array_pop($folders)) {
            $node = $node->addNode($folder, 'nt:folder');
        }
        if (!$node->isNodeType('nt:folder')) {
            throw new \LogicException($path.' is not a folder but '.$node->getPrimaryNodeType()->getName());
        }

        return $node;
    }

    /**
     * @param string $path   path relative to the $root.
     * @param bool   $create whether to create the file if it does not exist yet.
     *
     * @return NodeInterface The node at $root/$path
     */
    private function getFileNode($path, $create = false)
    {
        $fileName = PathHelper::getNodeName('/'.$path);
        $folderPath = PathHelper::getParentPath('/'.$path);
        $folder = $this->getFolderNode($folderPath, $create);

        if ($folder->hasNode($fileName) || !$create) {
            return $folder->getNode($fileName);
        }

        $file = $folder->addNode($fileName, 'nt:file');
        $file->addNode('jcr:content', 'nt:resource');

        return $file;
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $location = $this->applyPathPrefix($path);

        return $this->session->nodeExists($location);
    }

    /**
     * @param NodeInterface   $file
     * @param string          $path path relative to the $root.
     * @param string|resource $contents
     * @param Config          $config
     *
     * @return array Metadata for return value.
     */
    private function writeMeta(NodeInterface $file, $path, $contents, Config $config)
    {
        $content = $file->getNode('jcr:content');
        if (!$mimetype = $config->get('mimetype')) {
            if (is_string($contents)) {
                $mimetype = Util::guessMimeType($path, $contents);
            } else {
                $mimetype = null;
            }
        }
        $content->setProperty('jcr:mimeType', $mimetype);
        if ($encoding = $config->get('encoding')) {
            $content->setProperty('jcr:encoding', $encoding);
        }
        $type = 'file';
        $result = compact('mimetype', 'type', 'path');

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        $file = $this->getFileNode($path, true);
        $content = $file->getNode('jcr:content');
        $content->setProperty('jcr:data', $contents);

        $result = $this->writeMeta($file, $path, $contents, $config);
        $result['size'] = $content->getProperty('jcr:data')->getLength();
        $result['contents'] = $contents;

        $this->session->save();

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        $file = $this->getFileNode($path, true);
        $content = $file->getNode('jcr:content');

        $content->setProperty('jcr:data', $resource);

        $this->session->save();

        return $this->writeMeta($file, $path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        try {
            $file = $this->getFileNode($path);
        } catch (PathNotFoundException $e) {
            return false;
        }
        $content = $file->getNode('jcr:content');
        $result = $this->getFileInfo($file);
        $result['stream'] = $content->getPropertyValue('jcr:data', PropertyType::BINARY);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        try {
            $file = $this->getFileNode($path);
        } catch (PathNotFoundException $e) {
            return false;
        }
        $content = $file->getNode('jcr:content');
        $result = $this->getFileInfo($file);
        $result['contents'] = $content->getPropertyValue('jcr:data', PropertyType::STRING);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $location = $this->applyPathPrefix($path);
        if (!$this->session->nodeExists($location)) {
            return false;
        }
        $destination = $this->applyPathPrefix($newpath);
        $parentFolder = $this->applyPathPrefix(PathHelper::getParentPath($newpath));
        $this->getFolderNode($parentFolder, true);

        $this->session->move($location, $destination);
        $this->session->save();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        $location = $this->applyPathPrefix($path);
        if (!$this->session->nodeExists($location)) {
            return false;
        }
        $destination = $this->applyPathPrefix($newpath);
        $parentFolder = $this->applyPathPrefix(PathHelper::getParentPath($newpath));
        $this->getFolderNode($parentFolder, true);

        $this->session->save();
        $this->session->getWorkspace()->copy($location, $destination);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        try {
            $node = $this->getFileNode($path);
        } catch (PathNotFoundException $e) {
            return false;
        }
        $node->remove();
        $this->session->save();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        try {
            $folder = $this->getFolderNode($directory);
        } catch (PathNotFoundException $e) {
            return array();
        }

        $result = [];
        $files = [];
        foreach ($folder->getNodes() as $node) {
            $files[] = $node;
        }
        while ($file = array_shift($files)) {
            $result[] = $this->getFileInfo($file, $this->removePathPrefix($file->getPath()));
            if ($recursive && $file->isNodeType('nt:folder')) {
                foreach ($file->getNodes() as $node) {
                    $files[] = $node;
                }
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        try {
            $file = $this->getFileNode($path);
        } catch (PathNotFoundException $e) {
            return false;
        }

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    private function getFileInfo(NodeInterface $file)
    {
        $type = $file->isNodeType('nt:folder') ? 'folder' : 'file';

        $result = array(
            'type' => $type,
            'path' => $this->removePathPrefix($file->getPath()),
        );

        if ('file' === $type) {
            $content = $file->getNode('jcr:content');
            $result['size'] = $content->getProperty('jcr:data')->getLength();
            $result['timestamp'] = $content->getPropertyValue('jcr:lastModified', PropertyType::LONG);
            if ($content->hasProperty('jcr:mimeType')) {
                $result['mimetype'] = $content->getPropertyValue('jcr:mimeType');
            }
            if ($content->hasProperty('jcr:encoding')) {
                $result['encoding'] = $content->getPropertyValue('jcr:encoding');
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $this->getFolderNode($dirname, true);

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        try {
            $this->getFolderNode($dirname)->remove();
        } catch (PathNotFoundException $e) {
            return false;
        } catch (\LogicException $e) {
            return false;
        }
        $this->session->save();

        return true;
    }
}
