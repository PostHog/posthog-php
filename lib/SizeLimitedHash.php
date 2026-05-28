<?php

namespace PostHog;

/**
 * Size-limited two-level set used for feature flag call deduplication.
 *
 * @internal
 */
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

    /**
     * Create a size-limited hash.
     *
     * @param int $size Maximum number of top-level keys before the mapping resets.
     */
    public function __construct($size)
    {
        $this->size = $size;
        $this->mapping = [];
    }

    /**
     * Add an element under a top-level key.
     *
     * @param string $key Top-level key.
     * @param string $element Element to mark as present.
     * @return void
     */
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

    /**
     * Check whether an element exists under a top-level key.
     *
     * @param string $key Top-level key.
     * @param string $element Element to look up.
     * @return bool
     */
    public function contains($key, $element)
    {
        return isset($this->mapping[$key][$element]);
    }

    /**
     * Count top-level keys in the mapping.
     *
     * @return int
     */
    public function count()
    {
        return count($this->mapping);
    }
}
