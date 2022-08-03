<?php

namespace PostHog;

use Exception;

class InconclusiveMatchException extends Exception {
    public function errorMessage() {

      $errorMsg = 'Error on line '.$this->getLine().' in '.$this->getFile()
      .': <b> Inconclusive Match:'.$this->getMessage().'</b>';
      
      return $errorMsg;
    }
}

class FeatureFlag
{
    static function match_property($property, $property_values)
    {
        
        $key = $property["key"];
        $operator = $property["operator"] ?? "exact";
        $value = $property["value"];
    
        if (!array_key_exists($key, $property_values)) {
            throw new InconclusiveMatchException("Can't match properties without a given property value");
        }
    
    
        if ($operator == "is_not_set") {
            throw new InconclusiveMatchException("can't match properties with operator is_not_set");
        }
    
        $override_value = $property_values[$key];
        
        if ($operator == "exact") {
            if (is_array($value)) {
                return in_array($override_value, $value);
            }
            return $value == $override_value;
        }
    
        if ($operator == "is_not") {
            if (is_array($value)) {
                return !in_array($override_value, $value);
            }
            return $value !== $override_value;
        }
    
        if ($operator == "is_set") {
            return array_key_exists($key, $property_values);
        }
    
        if ($operator == "icontains") {
            return strpos(strtolower(strval($override_value)), strtolower(strval($value))) !== false;
        }
    
        if ($operator == "not_icontains") {
            return strpos(strtolower(strval($override_value)), strtolower(strval($value))) == false;
        }
    
        if ($operator == "regex") {
            return preg_match($value, $override_value);
        }
    
        if ($operator == "not_regex") {
            return !preg_match($value, $override_value);
        }
    
        if ($operator == "gt") {
            return gettype($value) == gettype($override_value) && $override_value > $value;
        }
    
        if ($operator == "gte") {
            return gettype($value) == gettype($override_value) && $override_value >= $value;
        }
    
        if ($operator == "lt") {
            return gettype($value) == gettype($override_value) && $override_value < $value;
        }
    
        if ($operator == "lte") {
            return gettype($value) == gettype($override_value) && $override_value <= $value;
        }
    
        return false;
    }

    static function _hash($key, $distinct_id, $salt = "")
    {

    }

    static function get_matching_variant($flag, $distinct_id)
    {
        $variants = variant_lookup_table($flag);

        foreach ($variants as $variant) {
            if (
                _hash($flag["key"], $distinct_id, "variant") >= $variant["value_min"]
                && _hash($flag["key"], $distinct_id, "variant") < $variant["value_max"]
            ) {
                return $variant["key"];
            }
        }

        return null;
    }

    static function variant_lookup_table($feature_flag)
    {
        $lookup_table = [];
        $value_min = 0;
        $multivariates = (($feature_flag['filters'] ?? [])['multivariate'] ?? [])['variants'] ?? [];
        
        foreach ($multivariates as $variant) {
            $value_max = $value_min + $variant["rollout_percentage"] / 100;

            array_push($lookup_table, [
                "value_min" => $value_min,
                "value_max" => $value_max,
                "key" => $variant["key"]
            ]);
            $value_min = $value_max;
        }

        return $lookup_table;
    }

    static function match_feature_flag_properties($flag, $distinct_id, $properties)
    {
        $flag_conditions = ($flag["filters"] ?? [])["groups"] ?? [];
        $is_inconclusive = false;

        foreach ($flag_conditions as $condition) {
            try {
                if (is_condition_match($flag, $distinct_id, $condition, $properties)) {
                    return get_matching_variant($flag, $distinct_id) ?? true;
                }
            } catch (InconclusiveMatchException $e) {
                $is_inconclusive = true;
            }
        }

        if ($is_inconclusive) {
            throw new InconclusiveMatchException("Can't determine if feature flag is enabled or not with given properties");
        }

        return false;
    }

    static function is_condition_match($feature_flag, $distinct_id, $condition, $properties)
    {
        $rollout_percentage = $condition["rollout_percentage"];

        if (count($condition['properties'] ?? []) > 0) {
            foreach ($condition['properties'] as $property) {
                if (!match_property($property, $properties)) {
                    return false;
                }
            }

            if (!is_null($rollout_percentage)) {
                return true;
            }
        }

        if (!is_null($rollout_percentage) && _hash($feature_flag["key"], $distinct_id) > ($rollout_percentage / 100)) {
            return false;
        }

        return true;
    }

}
