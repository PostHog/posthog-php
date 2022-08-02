<?php

namespace PostHog\Test;

use PHPUnit\Framework\TestCase;
use PostHog\FeatureFlag;

class FeatureFlagMatch extends TestCase
{

    public function testMatchPropertyEquals(): void
    {   
        $prop = [
            "key" => "key",
            "value" => "value",
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => null,
        ]));
        
        // self::expectException(InconclusiveMatchException::class);
        // FeatureFlag::match_property($prop, [
        //     "key2" => "value2",
        // ]);

        $prop = [
            "key" => "key",
            "value" => "value",
            "operator" => "exact"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        $prop = [
            "key" => "key",
            "value" => ["value1", "value2", "value3"],
            "operator" => "exact"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value1",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value3",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "value4",
        ]));

        // self::expectException(InconclusiveMatchException::class);
        // FeatureFlag::match_property($prop, [
        //     "key2" => "value2",
        // ]);

    }

    public function testMatchPropertyNotIn(): void
    {   
        $prop = [
            "key" => "key",
            "value" => "value",
            "operator" => "is_not"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => null,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "",
        ]));

        $prop = [
            "key" => "key",
            "value" => ["value1", "value2", "value3"],
            "operator" => "is_not"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value4",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value5",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value6",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => null,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "value3",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "value1",
        ]));

        // self::expectException(InconclusiveMatchException::class);
        // FeatureFlag::match_property($prop, [
        //     "key2" => "value2",
        // ]);

    }

    public function testMatchPropertyIsSet(): void
    {   
        $prop = [
            "key" => "key",
            "value" => "is_set",
            "operator" => "is_set"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => null,
        ]));
        
    }
    

    public function testMatchPropertyContains(): void
    {   
        $prop = [
            "key" => "key",
            "value" => "valUe",
            "operator" => "icontains"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value2",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value3",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "value4",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "343tfvalue5",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "Alakazam",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => 123,
        ]));

        $prop = [
            "key" => "key",
            "value" => 3,
            "operator" => "icontains"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "3",
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 323,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => "val3",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "three",
        ]));
    }

    // public function testMatchPropertyRegex(): void
    // {   
    //     $prop = [
    //         "key" => "key",
    //         "value" => "/.com/",
    //         "operator" => "regex"
    //     ];

    //     self::assertTrue(FeatureFlag::match_property($prop, [
    //         "key" => "value.com",
    //     ]));

    //     self::assertTrue(FeatureFlag::match_property($prop, [
    //         "key" => "value2.com",
    //     ]));

    //     self::assertTrue(FeatureFlag::match_property($prop, [
    //         "key" => ".com343tfvalue5",
    //     ]));

    //     self::assertFalse(FeatureFlag::match_property($prop, [
    //         "key" => "Alakazam",
    //     ]));

    //     self::assertFalse(FeatureFlag::match_property($prop, [
    //         "key" => 123,
    //     ]));

    // }

    public function testMatchPropertyMathOperators(): void
    {   
        $prop = [
            "key" => "key",
            "value" => 1,
            "operator" => "gt"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 2,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 3,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => 0,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => -1,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "23",
        ]));

        $prop = [
            "key" => "key",
            "value" => 1,
            "operator" => "lt"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 0,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => -1,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => -3,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => 1,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "1",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "3",
        ]));

        $prop = [
            "key" => "key",
            "value" => 1,
            "operator" => "gte"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 1,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 2,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => 0,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => -1,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "3",
        ]));

        $prop = [
            "key" => "key",
            "value" => 43,
            "operator" => "lte"
        ];

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 0,
        ]));

        self::assertTrue(FeatureFlag::match_property($prop, [
            "key" => 43,
        ]));


        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => 44,
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "1",
        ]));

        self::assertFalse(FeatureFlag::match_property($prop, [
            "key" => "3",
        ]));

    }
}