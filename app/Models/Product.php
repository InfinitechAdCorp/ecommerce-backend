<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'original_price',
        'category',
        'model',
        'images',
        'ideal_for',
        'specifications',
        'colors',
        'in_stock',
        'featured',
    ];

    protected $casts = [
        'images' => 'array',
        'ideal_for' => 'array',
        'specifications' => 'array',
        'colors' => 'array',
        'in_stock' => 'boolean',
        'featured' => 'boolean',
        // Enhanced decimal casting for large numbers
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
    ];

    // Accessor to get full image URLs
    public function getImagesAttribute($value)
    {
        if (!$value) return [];
        
        $images = json_decode($value, true) ?? [];
        return array_map(function ($image) {
            return str_starts_with($image, 'http') ? $image : asset('storage/' . $image);
        }, $images);
    }

    // Mutator to store relative paths
    public function setImagesAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['images'] = json_encode($value);
        } else {
            $this->attributes['images'] = $value;
        }
    }

    // Enhanced price accessor to handle large numbers properly
    public function getPriceAttribute($value)
    {
        return $value ? (float) $value : 0;
    }

    public function getOriginalPriceAttribute($value)
    {
        return $value ? (float) $value : null;
    }

    // Accessor for specifications with updated structure
    public function getSpecificationsAttribute($value)
    {
        if (!$value) {
            return [
                'dimensions' => null,
                'battery_type' => null,
                'motor_power' => null,
                'main_features' => null,
                'front_rear_suspension' => null,
                'front_tires' => null,
                'rear_tires' => null,
            ];
        }

        $specs = json_decode($value, true) ?? [];
        
        // Ensure all possible fields exist with null defaults
        $defaultSpecs = [
            'dimensions' => null,
            'battery_type' => null,
            'motor_power' => null,
            'main_features' => null,
            'front_rear_suspension' => null,
            'front_tires' => null,
            'rear_tires' => null,
        ];

        return array_merge($defaultSpecs, $specs);
    }
}
