<?php

namespace PostHog;

class SizeLimitedHash
{
    /**
     * @var int
     */
    private $size;

    /**
     * @var array
     */
    private $mapping;

    public function __construct($size)
    {
        $this->size = $size;
        $this->mapping = [];
    }

    public function add($key, $element)
    {

        if (count($this->mapping) >= $this->size) {
            $this->mapping = [];
        }

        if (array_key_exists($key, $this->mapping)) {
            $this->mapping[$key][$element] = true;
        } else {
            $this->mapping[$key] = [$element => true];
        }
    }

    public function contains($key, $element)
    {
        return isset($this->mapping[$key][$element]);
    }

    public function count()
    {
        return count($this->mapping);
    }
}
