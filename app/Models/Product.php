<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;

#[Fillable([
    'category_id', 'name', 'name_kh', 'sku', 'description', 'price', 'cost_price',
    'reorder_level', 'normal_points', 'bonus_multiplier', 'is_featured',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The most images a product can have.
     */
    public const MAX_IMAGES = 8;

    /**
     * Get the product's images; the first is the cover.
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Store uploaded files as new images after the existing ones.
     *
     * @param  list<UploadedFile>  $files
     */
    public function addImages(array $files): void
    {
        $next = (int) $this->images()->max('sort_order') + ($this->images()->exists() ? 1 : 0);

        foreach ($files as $file) {
            $this->images()->create([
                'path' => $file->store('products/'.$this->id, ProductImage::DISK),
                'sort_order' => $next++,
            ]);
        }
    }

    /**
     * Delete the given images of this product, including their stored files.
     *
     * @param  list<int>  $imageIds
     */
    public function removeImages(array $imageIds): void
    {
        $this->images()->whereKey($imageIds)->get()->each(function (ProductImage $image) {
            $image->deleteFile();
            $image->delete();
        });
    }

    /**
     * Get the category the product belongs to.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'reorder_level' => 'integer',
            'normal_points' => 'integer',
            'bonus_multiplier' => 'decimal:2',
            'is_featured' => 'boolean',
        ];
    }
}
