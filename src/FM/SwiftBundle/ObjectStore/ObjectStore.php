<?php

namespace FM\SwiftBundle\ObjectStore;

use FM\SwiftBundle\Event\ContainerEvent;
use FM\SwiftBundle\Event\ObjectEvent;
use FM\SwiftBundle\Exception\DuplicateException;
use FM\SwiftBundle\Exception\NotFoundException;
use FM\SwiftBundle\Metadata\DriverInterface as MetadataDriverInterface;
use FM\SwiftBundle\Metadata\Metadata;
use FM\SwiftBundle\ObjectStore\DriverInterface as StoreDriverInterface;
use FM\SwiftBundle\SwiftEvents;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Main class to handle containers and objects.
 */
class ObjectStore
{
    /**
     * @var StoreDriverInterface
     */
    protected $storeDriver;

    /**
     * @var MetadataDriverInterface
     */
    protected $metadataDriver;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * Constructor.
     *
     * @param StoreDriverInterface    $storeDriver
     * @param MetadataDriverInterface $metadataDriver
     */
    public function __construct(StoreDriverInterface $storeDriver, MetadataDriverInterface $metadataDriver)
    {
        $this->storeDriver     = $storeDriver;
        $this->metadataDriver  = $metadataDriver;
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * @param  string    $name
     * @return Container
     */
    public function getContainer($name)
    {
        if (!$this->containerExists($name)) {
            return null;
        }

        $container = new Container($name);
        $container->setMetadata($this->metadataDriver->get($container->getPath()));

        return $container;
    }

    /**
     * @param string|Container $container
     *
     * @throws \InvalidArgumentException When $container is neither a string nor a Container instance
     *
     * @return boolean
     */
    public function containerExists($container)
    {
        if (is_string($container)) {
            $container = new Container($container);
        }

        if (!$container instanceof Container) {
            throw new \InvalidArgumentException('containerExists expects a Container instance or a string');
        }

        return $this->storeDriver->containerExists($container);
    }

    /**
     * @param Container $container
     *
     * @throws DuplicateException When container already exists
     */
    public function createContainer(Container $container)
    {
        if ($this->storeDriver->containerExists($container)) {
            throw new DuplicateException(sprintf('Container "%s" already exists', $container->getPath()));
        }

        $this->storeDriver->createContainer($container);
        $this->metadataDriver->set($container->getPath(), $container->getMetadata());

        $this->dispatchEvent(SwiftEvents::CREATE_CONTAINER, new ContainerEvent($container));
    }

    /**
     * @param Container $container
     *
     * @throws NotFoundException When container does not exist
     */
    public function updateContainer(Container $container)
    {
        if (!$this->storeDriver->containerExists($container)) {
            throw new NotFoundException(sprintf('Container "%s" does not exist', $container->getPath()));
        }

        $this->metadataDriver->set($container->getPath(), $container->getMetadata());

        $this->dispatchEvent(SwiftEvents::UPDATE_CONTAINER, new ContainerEvent($container));
    }

    /**
     * @param Container $container
     *
     * @throws NotFoundException When container does not exist
     */
    public function removeContainer(Container $container)
    {
        if (!$this->storeDriver->containerExists($container)) {
            throw new NotFoundException(sprintf('Container "%s" does not exist', $container->getPath()));
        }

        $this->storeDriver->removeContainer($container);
        $this->metadataDriver->remove($container->getPath());

        $this->dispatchEvent(SwiftEvents::REMOVE_CONTAINER, new ContainerEvent($container));
    }

    /**
     * @param Container $container
     * @param string    $prefix
     * @param string    $delimiter
     * @param integer   $marker
     * @param integer   $endMarker
     * @param integer   $limit
     *
     * @throws NotFoundException When container does not exist
     *
     * @return \FM\SwiftBundle\ObjectStore\Object[]
     */
    public function listContainer(Container $container, $prefix = null, $delimiter = null, $marker = null, $endMarker = null, $limit = 10000)
    {
        if (!$this->storeDriver->containerExists($container)) {
            throw new NotFoundException(sprintf('Container "%s" does not exist', $container->getPath()));
        }

        // search for the files
        $list = $this->storeDriver->listContainer($container, $prefix, $delimiter, $marker, $endMarker, $limit);

        // create objects for all files
        return array_map(function($name) use ($container) {
            $object = new Object($container, $name);
            $object->setMetadata($this->metadataDriver->get($object->getPath()));

            return $object;
        }, $list);
    }

    /**
     * @param string|Container $container
     * @param string           $name
     *
     * @throws \InvalidArgumentException When $container is neither a string nor a Container instance
     *
     * @return boolean
     */
    public function objectExists($container, $name)
    {
        if (is_string($container)) {
            $container = new Container($container);
        }

        if (!$container instanceof Container) {
            throw new \InvalidArgumentException('objectExists expects a Container instance or a string');
        }

        return $this->storeDriver->objectExists(new Object($container, $name));
    }

    /**
     * @param string|Container $container
     * @param string $name
     *
     * @return \FM\SwiftBundle\ObjectStore\Object|null
     */
    public function getObject($container, $name)
    {
        if (is_string($container)) {
            $container = new Container($container);
        }

        if (!$this->objectExists($container, $name)) {
            return null;
        }

        $object = new Object($container, $name);
        $object->setMetadata($this->metadataDriver->get($object->getPath()));

        return $object;
    }

    /**
     * @param \FM\SwiftBundle\ObjectStore\Object $object
     * @param string                             $content
     * @param string                             $checksum
     */
    public function updateObject(Object $object, $content = null, $checksum = null)
    {
        // update content if given
        if ($content) {
            $this->storeDriver->updateObject($object, $content, $checksum);
        }

        $this->metadataDriver->set($object->getPath(), $object->getMetadata());

        $this->dispatchEvent(SwiftEvents::UPDATE_OBJECT, new ObjectEvent($object));
    }

    /**
     * @param \FM\SwiftBundle\ObjectStore\Object $source
     * @param  Container                         $destination
     * @param  string                            $name
     * @param  Metadata                          $metadata    Extra metadata
     *
     * @throws NotFoundException
     *
     * @return \FM\SwiftBundle\ObjectStore\Object
     */
    public function copyObject(Object $source, Container $destination, $name, Metadata $metadata = null)
    {
        if (!$this->storeDriver->objectExists($source)) {
            throw new NotFoundException(sprintf('Object "%s" does not exist', $source->getPath()));
        }

        $object = $this->storeDriver->copyObject($source, $destination, $name);

        // set metadata
        $sourceMetadata = $this->metadataDriver->get($source->getPath());
        if ($metadata) {
            $sourceMetadata->add($metadata->all());
        }
        $this->metadataDriver->set($object->getPath(), $sourceMetadata);

        $this->dispatchEvent(SwiftEvents::COPY_OBJECT, new ObjectEvent($object));

        return $object;
    }

    /**
     * @param \FM\SwiftBundle\ObjectStore\Object $object
     *
     * @throws NotFoundException
     */
    public function removeObject(Object $object)
    {
        if (!$this->storeDriver->objectExists($object)) {
            throw new NotFoundException(sprintf('Object "%s" does not exist', $object->getPath()));
        }

        $this->storeDriver->removeObject($object);
        $this->metadataDriver->remove($object->getPath());

        $this->dispatchEvent(SwiftEvents::REMOVE_OBJECT, new ObjectEvent($object));
    }

    /**
     * @param \FM\SwiftBundle\ObjectStore\Object $object
     *
     * @throws NotFoundException
     */
    public function touchObject(Object $object)
    {
        if (!$this->storeDriver->objectExists($object)) {
            throw new NotFoundException(sprintf('Object "%s" does not exist', $object->getPath()));
        }

        $this->storeDriver->touchObject($object);
    }

    /**
     * @param \FM\SwiftBundle\ObjectStore\Object $object
     *
     * @return string
     */
    public function getObjectChecksum(Object $object)
    {
        return $this->storeDriver->getObjectChecksum($object);
    }

    /**
     * @param \FM\SwiftBundle\ObjectStore\Object $object
     *
     * @return File
     */
    public function getObjectFile(Object $object)
    {
        return $this->storeDriver->getObjectFile($object);
    }

    /**
     * @param string $name
     * @param Event  $event
     */
    protected function dispatchEvent($name, Event $event)
    {
        $this->eventDispatcher->dispatch($name, $event);
    }
}
