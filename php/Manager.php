<?php
namespace EAMann\Sessionz;

use EAMann\Sessionz\Handlers\BaseHandler;

class Manager implements \SessionHandlerInterface {
    protected static $manager;

    /**
     * @var array
     */
    protected $handlers;

    /**
     * @var \SplStack
     */
    protected $delete_stack;

    /**
     * @var \SplStack
     */
    protected $clean_stack;

    /**
     * @var \SplStack
     */
    protected $create_stack;

    /**
     * @var \SplStack
     */
    protected $read_stack;

    /**
     * @var \SplStack
     */
    protected $write_stack;

    /**
     * Handler stack lock
     *
     * @var bool
     */
    protected $handlerLock;

    public function __construct()
    {

    }

    /**
     * Add a handler to the stack.
     *
     * @param Handler $handler
     *
     * @return static
     */
    public function addHandler($handler)
    {
        if ($this->handlerLock) {
            throw new \RuntimeException('Session handlers can’t be added once the stack is dequeuing');
        }
        if (is_null($this->handlers)) {
            $this->seedHandlerStack();
        }

        // DELETE
        $next_delete = $this->delete_stack->top();
        $this->delete_stack[] = function($session_id) use ($handler, $next_delete) {
            return call_user_func(array( $handler, 'delete'), $session_id, $next_delete);
        };

        // CLEAN
        $next_clean = $this->clean_stack->top();
        $this->clean_stack[] = function($lifetime) use ($handler, $next_clean) {
            return call_user_func(array( $handler, 'clean'), $lifetime, $next_clean);
        };

        // CREATE
        $next_create = $this->create_stack->top();
        $this->create_stack[] = function($path, $name) use ($handler, $next_create) {
            return call_user_func(array( $handler, 'create'), $path, $name, $next_create);
        };

        // READ
        $next_read = $this->read_stack->top();
        $this->read_stack[] = function($session_id) use ($handler, $next_read) {
            return call_user_func(array( $handler, 'read'), $session_id, $next_read);
        };

        // WRITE
        $next_write = $this->write_stack->top();
        $this->write_stack[] = function($session_id, $session_data) use ($handler, $next_write) {
            return call_user_func(array( $handler, 'write'), $session_id, $session_data, $next_write);
        };

        return $this;
    }

    /**
     * Seed handler stack with first callable
     *
     * @throws \RuntimeException if the stack is seeded more than once
     */
    protected function seedHandlerStack()
    {
        if (!is_null($this->handlers)) {
            throw new \RuntimeException('Handler stacks can only be seeded once.');
        }
        $base = new BaseHandler();

        $this->delete_stack = new \SplStack;
        $this->clean_stack = new \SplStack;
        $this->create_stack = new \SplStack;
        $this->read_stack = new \SplStack;
        $this->write_stack = new \SplStack;
        $this->handlers = [];

        $this->delete_stack->setIteratorMode(\SplDoublyLinkedList::IT_MODE_LIFO | \SplDoublyLinkedList::IT_MODE_KEEP);
        $this->clean_stack->setIteratorMode(\SplDoublyLinkedList::IT_MODE_LIFO | \SplDoublyLinkedList::IT_MODE_KEEP);
        $this->create_stack->setIteratorMode(\SplDoublyLinkedList::IT_MODE_LIFO | \SplDoublyLinkedList::IT_MODE_KEEP);
        $this->read_stack->setIteratorMode(\SplDoublyLinkedList::IT_MODE_LIFO | \SplDoublyLinkedList::IT_MODE_KEEP);
        $this->write_stack->setIteratorMode(\SplDoublyLinkedList::IT_MODE_LIFO | \SplDoublyLinkedList::IT_MODE_KEEP);

        $this->delete_stack[] = array( $base, 'delete' );
        $this->clean_stack[] = array( $base, 'clean' );
        $this->create_stack[] = array( $base, 'create' );
        $this->read_stack[] = array( $base, 'read' );
        $this->write_stack[] = array( $base, 'write' );
        $this->handlers[] = $base;
    }

    /**
     * Initialize the session manager.
     *
     * Invoking this function multiple times will reset the manager itself
     * and purge any handlers already registered with the system.
     *
     * @return Manager
     */
    public static function initialize()
    {
        $manager = self::$manager = new self();
        $manager->seedHandlerStack();

        session_set_save_handler($manager);

        return $manager;
    }

    /**
     * Close the current session.
     *
     * Will iterate through all handlers registered to the manager and
     * remove them from the stack. This has the effect of removing the
     * objects from scope and triggering their destructors. Any cleanup
     * should happen there.
     *
     * @return true
     */
    public function close()
    {
        $this->handlerLock = true;

        while (count($this->handlers) > 0) {
            array_pop($this->handlers);
            $this->delete_stack->pop();
            $this->clean_stack->pop();
            $this->create_stack->pop();
            $this->read_stack->pop();
            $this->write_stack->pop();
        }

        $this->handlerLock = false;
        return true;
    }

    /**
     * Destroy a session by either invalidating it or forcibly removing
     * it from session storage.
     *
     * @param string $session_id ID of the session to destroy.
     *
     * @return bool
     */
    public function destroy($session_id)
    {
        if (is_null($this->handlers)) {
            $this->seedHandlerStack();
        }

        /** @var callable $start */
        $start = $this->delete_stack->top();
        $this->handlerLock = true;
        $data = $start($session_id);
        $this->handlerLock = false;
        return $data;
    }

    /**
     * Clean up any potentially expired sessions (sessions with an age
     * greater than the specified maximum-allowed lifetime).
     *
     * @param int $maxlifetime Max number of seconds for which a session is valid.
     *
     * @return bool
     */
    public function gc($maxlifetime)
    {
        if (is_null($this->handlers)) {
            $this->seedHandlerStack();
        }

        /** @var callable $start */
        $start = $this->clean_stack->top();
        $this->handlerLock = true;
        $data = $start($maxlifetime);
        $this->handlerLock = false;
        return $data;
    }

    /**
     * Create a new session storage.
     *
     * @param string $save_path File location/path where sessions should be written.
     * @param string $name      Unique name of the storage instance.
     *
     * @return bool
     */
    public function open($save_path, $name)
    {
        if (is_null($this->handlers)) {
            $this->seedHandlerStack();
        }

        /** @var callable $start */
        $start = $this->create_stack->top();
        $this->handlerLock = true;
        $data = $start($save_path, $name);
        $this->handlerLock = false;
        return $data;
    }

    /**
     * Read data from the specified session.
     *
     * @param string $session_id ID of the session to read.
     *
     * @return string
     */
    public function read($session_id)
    {
        if (is_null($this->handlers)) {
            $this->seedHandlerStack();
        }

        /** @var callable $start */
        $start = $this->read_stack->top();
        $this->handlerLock = true;
        $data = $start($session_id);
        $this->handlerLock = false;
        return $data;
    }

    /**
     * Write data to a specific session.
     *
     * @param string $session_id   ID of the session to write.
     * @param string $session_data Serialized string of session data.
     *
     * @return bool
     */
    public function write($session_id, $session_data)
    {
        if (is_null($this->handlers)) {
            $this->seedHandlerStack();
        }

        /** @var callable $start */
        $start = $this->write_stack->top();
        $this->handlerLock = true;
        $data = $start($session_id, $session_data);
        $this->handlerLock = false;
        return $data;
    }
}