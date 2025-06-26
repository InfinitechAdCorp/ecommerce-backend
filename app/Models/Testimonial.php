<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'location',
        'rating',
        'title',
        'message',
        'product_id',
        'is_approved',
        'is_featured',
        'avatar_url',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_approved' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Scope for approved testimonials
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    // Scope for featured testimonials
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }
}
