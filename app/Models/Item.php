<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class Item extends Model
{
    use HasFactory;
    protected $table = 'item';
    protected $guarded = [];

    protected $casts = [
        'party_id' => 'array', // Automatically cast JSON to array
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    public function company()
    {
        return $this->belongsTo(company::class);
    }
    public function category()
    {
        return $this->belongsTo(Itemcategory::class, 'category_id');
    }
    public function party()
    {
        return $this->belongsTo(party::class, 'party_id');
    }
    public function getPartyAttribute()
    {
        if (is_array($this->party_id)) {
            // Fetch all related parties for the party_id array
            return party::whereIn('id', $this->party_id)->get();
        }

        // Handle cases where party_id is not an array (fallback)
        return null;
    }


     // Add relationship for item_meta
     public function itemMeta()
     {
         return $this->hasMany(Itemmeta::class, 'item_id');
     }
     
     // Function to get item_meta grouped by item_meta_type
     public function getItemMetaByType()
     {
         $metadata = $this->itemMeta()->get();
         $groupedMeta = [];
         
         foreach ($metadata as $meta) {
             $type = $meta->item_meta_type;
             if (!isset($groupedMeta[$type])) {
                 $groupedMeta[$type] = [];
             }
             $groupedMeta[$type][] = $meta;
         }
         
         return $groupedMeta;
     }

      /**
     * Get the latest job work rate from item_meta
     * before or on the given transaction date.
     *
     * @param  Carbon|null  $transactionDate
     * @return float|null
     */
      public function getLatestJobworkRate(Carbon $transactionDate = null)
    {
        $query = $this->itemMeta()
            ->where('item_meta_type', 'jobworkrate');

        if ($transactionDate) {
            $query->whereDate('amendment_date', '<=', $transactionDate);
        }

        $latestJobworkMeta = $query->orderBy('amendment_date', 'desc')->first();

        return $latestJobworkMeta ? $latestJobworkMeta->job_work_rate : 0;
    }

    /**
     * Get the latest scrap weight from item_meta
     * before or on the given transaction date.
     *
     * @param  Carbon|null  $transactionDate
     * @return float|null
     */
    public function getLatestScrapWeight(Carbon $transactionDate = null)
    {
        $query = $this->itemMeta()
            ->where('item_meta_type', 'scrap');

        if ($transactionDate) {
            $query->whereDate('amendment_date', '<=', $transactionDate);
        }

        $latestScrapMeta = $query->orderBy('amendment_date', 'desc')->first();

        return $latestScrapMeta ? $latestScrapMeta->scrap_wt : 0;
    }

       /**
     * Define relationship for parent item
     * This returns the parent item of the current item
     */
    public function parentItem()
    {
        return $this->belongsTo(Item::class, 'parent_id');
    }

    /**
     * Define relationship for child items
     * This returns all items that have their parent_id set to the current item's id
     */
    public function childItems()
    {
        return $this->hasMany(Item::class, 'parent_id');
    }
}
