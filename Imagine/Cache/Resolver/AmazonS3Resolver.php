<?php

namespace Liip\ImagineBundle\Imagine\Cache\Resolver;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class AmazonS3Resolver implements ResolverInterface
{
    /**
     * @var \AmazonS3
     */
    protected $storage;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $acl;

    /**
     * @var array
     */
    protected $objUrlOptions;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Constructs a cache resolver storing images on Amazon S3.
     *
     * @param \AmazonS3 $storage The Amazon S3 storage API. It's required to know authentication information.
     * @param string $bucket The bucket name to operate on.
     * @param string $acl The ACL to use when storing new objects. Default: owner read/write, public read
     * @param array $objUrlOptions A list of options to be passed when retrieving the object url from Amazon S3.
     */
    public function __construct(\AmazonS3 $storage, $bucket, $acl = \AmazonS3::ACL_PUBLIC, array $objUrlOptions = array())
    {
        $this->storage = $storage;

        $this->bucket = $bucket;
        $this->acl = $acl;

        $this->objUrlOptions = $objUrlOptions;
    }

    /**
     * Sets the logger to be used.
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public function resolve($path, $filter)
    {
        $objectPath = $this->getObjectPath($path, $filter);

        return $this->objectExists($objectPath) ? $this->getObjectUrl($objectPath): null;
    }

    /**
     * {@inheritDoc}
     */
    public function store(Response $response, $path, $filter)
    {
        $objectPath = $this->getObjectPath($path, $filter);

        $storageResponse = $this->storage->create_object($this->bucket, $objectPath, array(
            'body' => $response->getContent(),
            'contentType' => $response->headers->get('Content-Type'),
            'length' => strlen($response->getContent()),
            'acl' => $this->acl,
        ));

        if ($storageResponse->isOK()) {
            $response->setStatusCode(301);
            $response->headers->set('Location', $this->getObjectUrl($objectPath));
        } else {
            if ($this->logger) {
                $this->logger->warning('The object could not be created on Amazon S3.', array(
                    'objectPath' => $objectPath,
                    'filter' => $filter,
                    's3_response' => $storageResponse,
                ));
            }
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */

    public function remove($path, $filter)
    {
        $objectPath = $this->getObjectPath($path, $filter);

        if ($this->objectExists($objectPath)) {
            return $this->storage->delete_object($this->bucket, $objectPath)->isOK();
        }

        // A non-existing object to delete: done!
        return true;
    }

    /**
     * Sets a single option to be passed when retrieving an objects URL.
     *
     * If the option is already set, it will be overwritten.
     *
     * @see \AmazonS3::get_object_url() for available options.
     *
     * @param string $key The name of the option.
     * @param mixed $value The value to be set.
     *
     * @return AmazonS3Resolver $this
     */
    public function setObjectUrlOption($key, $value)
    {
        $this->objUrlOptions[$key] = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function clear($cachePrefix)
    {
        // TODO: implement cache clearing for Amazon S3 service
    }

    /**
     * Returns the object path within the bucket.
     *
     * @param string $path The base path of the resource.
     * @param string $filter The name of the imagine filter in effect.
     *
     * @return string The path of the object on S3.
     */
    protected function getObjectPath($path, $filter)
    {
        return str_replace('//', '/', $filter.'/'.$path);
    }

    /**
     * Returns the URL for an object saved on Amazon S3.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getObjectUrl($path)
    {
        return $this->storage->get_object_url($this->bucket, $path, 0, $this->objUrlOptions);
    }

    /**
     * Checks whether an object exists.
     *
     * @param string $objectPath
     *
     * @return bool
     */
    protected function objectExists($objectPath)
    {
        return $this->storage->if_object_exists($this->bucket, $objectPath);
    }
}
