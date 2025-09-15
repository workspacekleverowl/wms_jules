<?php

namespace Modules\Masters\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Itemmeta;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Voucher;
use App\Models\Vouchermeta;

class ProductController extends Controller
{
     // Create a new item
     public function store(Request $request)
     {
         $response = $this->checkPermission('Item-Insert');
         
         // If checkPermission returns a response (i.e., permission denied), return it.
         if ($response) {
             return $response;
         }
     
         $user = $request->user();
         $tenantId = $user->tenant_id;
         $activeCompanyId = $user->getActiveCompanyId();
     
         // First validate the item_type to determine which validation rules to apply next
         $itemTypeValidator = Validator::make($request->all(), [
             'item_type' => 'required|string|in:single,grouped',
         ]);
     
         if ($itemTypeValidator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'errors' => $itemTypeValidator->errors(),
             ], 422);
         }
     
         // Common validation rules for both single and grouped item types
         $commonRules = [
             'name' => 'required|string|max:255',
             'category_id' => [
                 'required',
                 Rule::exists('item_category', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                     $query->where('tenant_id', $tenantId)
                           ->where('company_id', $activeCompanyId);
                 }),
             ],
             'party_id' => 'required|array|min:1',
             'party_id.*' => [
                 'integer',
                 Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                     $query->where('tenant_id', $tenantId)
                           ->where('company_id', $activeCompanyId);
                 }),
             ],
             'material_price' => 'nullable|numeric',
             'raw_weight' => 'nullable|numeric',
             'gst_percent_rate' => 'required|integer',
             'hsn' => 'required|integer',
             'item_code' => 'required|string|max:255',
             'description' => 'nullable|string',
         ];
     
         // Additional validation rules specific to single item type
         $singleRules = [
             'finished_weight' => 'nullable|numeric',
         ];
     
         // Determine which rules to apply based on item type
         $validationRules = $commonRules;
         if ($request->item_type === 'single') {
             $validationRules = array_merge($commonRules, $singleRules);
         } else {
             // For grouped item type, ensure these fields are not present or are null
             if ($request->has('job_work_rate') && $request->job_work_rate !== null) {
                 return response()->json([
                     'status' => 422,
                     'message' => 'Validation failed',
                     'errors' => ['job_work_rate' => ['Job work rate should not be provided for grouped item type']],
                 ], 422);
             }
             
             if ($request->has('finished_weight') && $request->finished_weight !== null) {
                 return response()->json([
                     'status' => 422,
                     'message' => 'Validation failed',
                     'errors' => ['finished_weight' => ['Finished weight should not be provided for grouped item type']],
                 ], 422);
             }
             
             if ($request->has('scrap_weight') && $request->scrap_weight !== null) {
                 return response()->json([
                     'status' => 422,
                     'message' => 'Validation failed',
                     'errors' => ['scrap_weight' => ['Scrap weight should not be provided for grouped item type']],
                 ], 422);
             }
         }
     
         // Validate the request with the appropriate rules
         $validator = Validator::make($request->all(), $validationRules);
     
         if ($validator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'errors' => $validator->errors(),
             ], 422);
         }
     
         // Check for unique combination
         $existingItem = Item::where('name', $request->name)
             ->where('tenant_id', $tenantId)
             ->where('company_id', $activeCompanyId)
             ->where('category_id', $request->category_id)
             ->where('item_code', $request->item_code)
             ->first();
     
         if ($existingItem) {
             return response()->json([
                 'status' => 409,
                 'message' => 'Item already exists for this Party',
             ], 409);
         }
     
         // Prepare common data for both item types
         $itemData = [
             'name' => $request->name,
             'item_type' => $request->item_type,
             'category_id' => $request->category_id,
             'party_id' =>  $request->party_id,
             'material_price' => $request->material_price ??null,
             'raw_weight' => $request->raw_weight ??null,
             'gst_percent_rate' => $request->gst_percent_rate,
             'hsn' => $request->hsn,
             'item_code' => $request->item_code,
             'description' => $request->description,
             'tenant_id' => $tenantId,
             'company_id' => $activeCompanyId,
             'status' => 'active',
         ];
     
         // Add fields specific to single item type
         if ($request->item_type === 'single') {
             $itemData['job_work_rate'] = null;
             $itemData['finished_weight'] = $request->finished_weight ?? null;
             $itemData['scrap_weight'] =null;
         } else {
             // For grouped items, set these fields to null
             $itemData['job_work_rate'] = null;
             $itemData['finished_weight'] = null;
             $itemData['scrap_weight'] = null;
         }
     
         // Create the item
         $item = Item::create($itemData);
     
         return response()->json([
             'status' => 200,
             'message' => 'Item created successfully',
             'record' => $item,
         ]);
     }

     public function addFinishedItem(Request $request)
     {
         $response = $this->checkPermission('Item-Insert');
         
         // If checkPermission returns a response (i.e., permission denied), return it.
         if ($response) {
             return $response;
         }
     
         $user = $request->user();
         $tenantId = $user->tenant_id;
         $activeCompanyId = $user->getActiveCompanyId();
     
         // First validate the parent item_id
         $parentValidator = Validator::make($request->all(), [
             'item_id' => [
                 'required',
                 'integer',
                 Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                     $query->where('tenant_id', $tenantId)
                         ->where('company_id', $activeCompanyId)
                         ->whereNull('deleted_at')
                         ->where('item_type', 'grouped'); 
                 }),
             ],
             // Check if finished_items is an array
             'finished_items' => 'nullable|array',
         ]);
     
         if ($parentValidator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'errors' => $parentValidator->errors(),
             ], 422);
         }
         
         // Get the parent item
         $parentItem = Item::where('id', $request->item_id)
             ->where('tenant_id', $tenantId)
             ->where('company_id', $activeCompanyId)
             ->where('item_type', 'grouped') // Changed from item_type to product_type
             ->first();
     
         if (!$parentItem) {
             return response()->json([
                 'status' => 404,
                 'message' => 'Parent item not found or is not a grouped item',
             ], 404);
         }
         
         // Validate each finished item in the array
         $finishedItemsValidator = Validator::make($request->all(), [
             'finished_items.*.name' => 'required|string|max:255',
             'finished_items.*.finished_weight' => 'nullable|numeric',
             'finished_items.*.item_code' => 'required|string|max:255',
             'finished_items.*.description' => 'nullable|string',
         ]);
     
         if ($finishedItemsValidator->fails()) {
             return response()->json([
                 'status' => 422,
                 'message' => 'Validation failed',
                 'errors' => $finishedItemsValidator->errors(),
             ], 422);
         }
     
         $createdItems = [];
         $duplicateItems = [];
         $errors = [];
     
         // Process each finished item
         foreach ($request->finished_items as $index => $finishedItemData) {
             // Check if a finished item with the same name already exists for this parent
             $existingFinishedItem = Item::where('name', $finishedItemData['name'])
                 ->where('parent_id', $parentItem->id)
                 ->where('tenant_id', $tenantId)
                 ->where('company_id', $activeCompanyId)
                 ->first();
     
             if ($existingFinishedItem) {
                 $duplicateItems[] = $finishedItemData['name'];
                 continue;
             }
     
             try {
                 // Create the finished item (child item)
                 $finishedItem = Item::create([
                     'name' => $finishedItemData['name'],
                     'parent_id' => $parentItem->id,
                     'item_type' => 'grouped', // Changed from item_type to product_type
                     'category_id' => $parentItem->category_id, // Inherit from parent
                     'party_id' => $parentItem->party_id, // Inherit from parent
                     'material_price' =>  $parentItem->material_price ??null,
                     'raw_weight' => $parentItem->raw_weight ?? null,
                     'finished_weight' => $finishedItemData['finished_weight'] ?? null, 
                     'gst_percent_rate' => $parentItem->gst_percent_rate, // Inherit from parent
                     'hsn' => $parentItem->hsn, // Inherit from parent
                     'item_code' => $finishedItemData['item_code'],
                     'description' => $finishedItemData['description'] ?? null,
                     'tenant_id' => $tenantId,
                     'company_id' => $activeCompanyId,
                     'status' => 'active',
                 ]);
                 
                 $createdItems[] = $finishedItem;
             } catch (\Exception $e) {
                 $errors[] = [
                     'index' => $index,
                     'name' => $finishedItemData['name'],
                     'error' => $e->getMessage()
                 ];
             }
         }
     
         $response = [
             'status' => 200,
             'message' => count($createdItems) . ' finished items added successfully',
             'records' => $createdItems,
         ];
     
         if (!empty($duplicateItems)) {
             $response['duplicates'] = $duplicateItems;
             $response['duplicate_message'] = 'The following items already exist and were skipped: ' . implode(', ', $duplicateItems);
         }
     
         if (!empty($errors)) {
             $response['errors'] = $errors;
             $response['error_message'] = 'Some items could not be created due to errors.';
         }
     
         return response()->json($response);
     }

    public function updateFinishedItem(Request $request)
    {
        $response = $this->checkPermission('Item-Update');

        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->whereNull('deleted_at')
                        ->where('item_type', 'grouped');
                }),
            ],
            'finished_item_id' => [
                'required',
                'integer',
                Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->whereNull('deleted_at')
                        ->where('item_type', 'grouped');
                }),
            ],
            'name' => 'required|string|max:255',
            'finished_weight' => 'nullable|numeric',
            'item_code' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $parentItem = Item::where('id', $request->item_id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('item_type', 'grouped')
            ->first();

        if (!$parentItem) {
            return response()->json([
                'status' => 404,
                'message' => 'Parent grouped item not found',
            ], 404);
        }

        $finishedItem = Item::where('id', $request->finished_item_id)
            ->where('parent_id', $parentItem->id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();

        if (!$finishedItem) {
            return response()->json([
                'status' => 404,
                'message' => 'Finished item not found under the given parent item',
            ], 404);
        }

        try {
            $finishedItem->update([
                'name' => $request->has('name') ? $request->name : $finishedItem->name,
                'finished_weight' => $request->has('finished_weight') ? $request->finished_weight : $finishedItem->finished_weight,
                'item_code' => $request->has('item_code') ? $request->item_code : $finishedItem->item_code,
                'description' => $request->has('description') ? $request->description : $finishedItem->description,
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Finished item updated successfully',
                'record' => $finishedItem,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update finished item',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


     public function getItemDetails(Request $request)
    {
      
        $response = $this->checkPermission('Item-Show');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Validate the request
        $validator = Validator::make($request->all(), [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->whereNull('deleted_at');
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        

        // Get the item
        $item = Item::where('id', $request->item_id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$item) {
            return response()->json([
                'status' => 404,
                'message' => 'Item not found',
            ], 404);
        }

        // If it's a single product, return the item details
        if ($item->item_type === 'single') {
            return response()->json([
                'status' => 200,
                'message' => 'Item details retrieved successfully',
                'data' => [
                    'items' => [
                        [
                            'id' => $item->id,
                            'name' => $item->name
                        ]
                    ]
                ],
            ]);
        } 
        // If it's a grouped product, return all child items
        else if ($item->item_type === 'grouped') {
            // Get all child items
            $childItems = Item::where('parent_id', $item->id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->select('id', 'name')
                ->get();

            if ($childItems->isEmpty()) {
                return response()->json([
                    'status' => 200,
                    'message' => 'No child items found for this grouped item',
                    'data' => [
                        'items' => []
                    ],
                ]);
            }

            return response()->json([
                'status' => 200,
                'message' => 'Child items retrieved successfully',
                'data' => [
                    'items' => $childItems
                ],
            ]);
        } else {
            return response()->json([
                'status' => 400,
                'message' => 'Invalid product type',
            ], 400);
        }
    }

    public function addItemMeta(Request $request)
    {
        $response = $this->checkPermission('Item-Update');
        
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        // Base validation rules
        $baseRules = [
            'parent_item_id' => [
                'required',
                'integer',
                Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->whereNull('deleted_at');
                }),
            ],
            'item_id' => [
                'required',
                'integer',
                Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->whereNull('deleted_at');
                }),
            ],
            'item_meta_type' => 'required|string|in:parameters,jobworkrate,scrap',
        ];

        // Validate base requirements first
        $baseValidator = Validator::make($request->all(), $baseRules);
        
        if ($baseValidator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $baseValidator->errors(),
            ], 422);
        }

        // Get the parent item and the target item
        $parentItem = Item::where('id', $request->parent_item_id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();
            
        $item = Item::where('id', $request->item_id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();

        // Validate relationship between parent_item_id and item_id
        if ($parentItem->item_type === 'single') {
            if ($request->parent_item_id != $request->item_id) {
                return response()->json([
                    'status' => 422,
                    'message' => 'For single product type, parent_item_id and item_id must be the same',
                ], 422);
            }
        } else { // grouped
            if ($item->parent_id != $request->parent_item_id) {
                return response()->json([
                    'status' => 422,
                    'message' => 'The specified item is not a child of the parent item',
                ], 422);
            }
        }

        // Default amendment date to today if not provided
        $amendmentDate = $request->amendment_date ?? now()->format('Y-m-d');

        // Add specific validation rules based on item_meta_type
        $additionalRules = [];
        
        switch ($request->item_meta_type) {
            case 'parameters':
                $additionalRules = [
                    'parameters' => 'required|array|min:1',
                    'parameters.*.Parameter' => 'required|string|max:255',
                    'parameters.*.Specification' => 'required|string|max:255',
                    'parameters.*.spl_chs' => 'required|string|max:255',
                    'parameters.*.inspection_method' => 'required|string|max:255',
                ];
                break;
                
            case 'jobworkrate':
                $additionalRules = [
                    'job_work_rate' => 'required|numeric|min:0',
                ];
                break;
                
            case 'scrap':
                $additionalRules = [
                    'scrap_wt' => 'required|numeric|min:0',
                ];
                break;
        }

        // Validate additional rules
        $additionalValidator = Validator::make($request->all(), $additionalRules);
        
        if ($additionalValidator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $additionalValidator->errors(),
            ], 422);
        }

        $createdMeta = [];
        $errors = [];

        // Process based on item_meta_type
        try {
            switch ($request->item_meta_type) {
                case 'parameters':
                    $skippedCount = 0;
                    foreach ($request->parameters as $paramData) {
                        // Check if the record already exists
                        $exists = Itemmeta::where([
                            'item_id' => $request->item_id,
                            'Parameter' => $paramData['Parameter'],
                            'Specification' => $paramData['Specification'],
                            'spl_chs' => $paramData['spl_chs'],
                            'inspection_method' => $paramData['inspection_method'],
                            'item_meta_type' => 'parameters'
                        ])->exists();
                        
                        // If record doesn't exist, create it
                        if (!$exists) {
                            $meta = Itemmeta::create([
                                'item_id' => $request->item_id,
                                'Parameter' => $paramData['Parameter'],
                                'Specification' => $paramData['Specification'],
                                'spl_chs' => $paramData['spl_chs'],
                                'inspection_method' => $paramData['inspection_method'],
                                'amendment_date' => $amendmentDate,
                                'item_meta_type' => 'parameters',
                            ]);
                            $createdMeta[] = $meta;
                        } else {
                            $skippedCount++;
                        }
                    }
                    
                    $message = count($createdMeta) . ' parameters added successfully';
                    if ($skippedCount > 0) {
                        $message .= ', ' . $skippedCount . ' parameters skipped (already exist)';
                    }
                    break;
                    
                case 'jobworkrate':
                        // Check if jobworkrate already exists for this item
                        // $existingJobworkrate = Itemmeta::where('item_id', $request->item_id)
                        //     ->where('item_meta_type', 'jobworkrate')
                        //     ->first();
                        
                        // if ($existingJobworkrate) {
                        //     // Update existing record
                        //     $existingJobworkrate->job_work_rate = $request->job_work_rate;
                        //     $existingJobworkrate->amendment_date = $amendmentDate;
                        //     $existingJobworkrate->save();
                        //     $createdMeta[] = $existingJobworkrate;
                        //     $message = 'Job work rate updated successfully';
                        // } else {
                            // Create new record
                            $meta = Itemmeta::create([
                                'item_id' => $request->item_id,
                                'job_work_rate' => $request->job_work_rate,
                                'amendment_date' => $amendmentDate,
                                'item_meta_type' => 'jobworkrate',
                            ]);
                            $createdMeta[] = $meta;
                            $message = 'Job work rate added successfully';
                        
                   
                        break;  
                    
                case 'scrap':
                    // Check if scrap already exists for this item
                    // $existingScrap = Itemmeta::where('item_id', $request->item_id)
                    //     ->where('item_meta_type', 'scrap')
                    //     ->first();
                    
                    // if ($existingScrap) {
                    //     // Update existing record
                    //     $existingScrap->scrap_wt = $request->scrap_wt;
                    //     $existingScrap->amendment_date = $amendmentDate;
                    //     $existingScrap->save();
                    //     $createdMeta[] = $existingScrap;
                    //     $message = 'Scrap weight updated successfully';
                    // } else {

                        if (is_null($parentItem->raw_weight) || empty($parentItem->raw_weight) || $parentItem->raw_weight == 0) {
                            return response()->json([
                                'status' => 422,
                                'message' => 'Raw weight must be set before adding scrap weight',
                                'errors' => $additionalValidator->errors(),
                            ], 422);
                        }

                        if ($parentItem->raw_weight <  $request->scrap_wt)
                        {
                            return response()->json([
                                'status' => 422,
                                'message' => 'Scrap weight cannot be greater than raw weight',
                                'errors' => $additionalValidator->errors(),
                            ], 422);
                        }
                        else{
                            // Create new record
                            $meta = Itemmeta::create([
                                'item_id' => $request->item_id,
                                'scrap_wt' => $request->scrap_wt,
                                'amendment_date' => $amendmentDate,
                                'item_meta_type' => 'scrap',
                            ]);
                            $createdMeta[] = $meta;
                            $message = 'Scrap weight added successfully';
                        }
                   
                    break;
            }
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }

        $response = [
            'status' => 200,
            'message' => $message,
            'records' => $createdMeta,
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
            $response['error_message'] = 'Some metadata could not be created due to errors.';
        }

        return response()->json($response);
    }

    public function getItemMeta(Request $request)
    {
        $response = $this->checkPermission('Item-Show');
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $validator = Validator::make($request->all(), [
            'item_id' => [
                'required',
                'integer',
                Rule::exists('item', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId)
                        ->whereNull('deleted_at');
                }),
            ],
            'item_meta_type' => 'required|string|in:parameters,jobworkrate,scrap',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $item = Item::where('id', $request->item_id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();

        $metaType = $request->item_meta_type;
        $metaCollection = collect();

        if ($item->item_type === 'single') {
            $metaCollection = Itemmeta::where('item_id', $item->id)
                ->where('item_meta_type', $metaType)
                ->get()
                ->map(function ($meta) use ($item) {
                    $meta->item_name = $item->name ?? null;
                    return $meta;
                });

        } else {
            $childItems = Item::where('parent_id', $item->id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->whereNull('deleted_at')
                ->get();

            foreach ($childItems as $child) {
                $childMeta = Itemmeta::where('item_id', $child->id)
                    ->where('item_meta_type', $metaType)
                    ->get()
                    ->map(function ($meta) use ($child) {
                        $meta->item_name = $child->name ?? null;
                        return $meta;
                    });

                $metaCollection = $metaCollection->merge($childMeta);
            }
        }

        return response()->json([
            'status' => 200,
            'message' => 'Meta data fetched successfully',
            'item_type' => $item->item_type,
            'meta' => $metaCollection->values(), // reset index
        ]);
    }

        
     // Update a Item
    public function update(Request $request, $id)
    {
        $response = $this->checkPermission('Item-Update');

        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

       

        // Check if flag is appendparty
        if ($request->flag === 'appendparty') {
            $validator = Validator::make($request->all(), [
                'party_id' => 'required|array|min:1',
                'party_id.*' => [
                    'integer',
                    Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                        $query->where('tenant_id', $tenantId)
                            ->where('company_id', $activeCompanyId);
                    }),
                ],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $Item = Item::where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->whereNull('deleted_at')
                ->first();

            if (!$Item) {
                return response()->json([
                    'status' => 200,
                    'message' => 'Item not found',
                ], 200);
            }

            // Decode existing party_id JSON and append new values
            $existingPartyIds = $Item->party_id ?? [];
            $newPartyIds = array_unique(array_merge($existingPartyIds, $request->party_id));

            $Item->update([
                'party_id' => $newPartyIds,
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Party IDs appended successfully',
                'record' => $Item,
            ]);
        }

         // First validate the item_type to determine which validation rules to apply next
         $itemTypeValidator = Validator::make($request->all(), [
            'item_type' => 'required|string|in:single,grouped',
        ]);

        if ($itemTypeValidator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $itemTypeValidator->errors(),
            ], 422);
        }
        
        // Common validation rules for both single and grouped item types
        $commonRules = [
            'name' => 'nullable|string|max:255',
            'category_id' => [
                'nullable',
                Rule::exists('item_category', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId);
                }),
            ],
            'party_id' => 'nullable|array',
            'party_id.*' => [
                'integer',
                Rule::exists('party', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->where('tenant_id', $tenantId)
                        ->where('company_id', $activeCompanyId);
                }),
            ],
            'material_price' => 'nullable|numeric',
            'raw_weight' => 'nullable|numeric',
            'gst_percent_rate' => 'nullable|integer',
            'hsn' => 'nullable|integer',
            'item_code' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ];

        // Additional validation rules specific to single item type
        $singleRules = [
            'finished_weight' => 'nullable|numeric',
        ];

        // Determine which rules to apply based on item type
        $validationRules = $commonRules;
        if ($request->item_type === 'single') {
            $validationRules = array_merge($commonRules, $singleRules);
            
        } else {
            // For grouped item type, ensure these fields are not present or are null
            if ($request->has('job_work_rate') && $request->job_work_rate !== null) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => ['job_work_rate' => ['Job work rate should not be provided for grouped item type']],
                ], 422);
            }
            
            if ($request->has('finished_weight') && $request->finished_weight !== null) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => ['finished_weight' => ['Finished weight should not be provided for grouped item type']],
                ], 422);
            }
            
            if ($request->has('scrap_weight') && $request->scrap_weight !== null) {
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => ['scrap_weight' => ['Scrap weight should not be provided for grouped item type']],
                ], 422);
            }
        }

        // Validate the request with the appropriate rules
        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $Item = Item::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$Item) {
            return response()->json([
                'status' => 200,
                'message' => 'Item not found',
            ], 200);
        }

        // If raw_weight is provided, validate against scrap weight
        if ($request->filled('raw_weight')) {
            $rawWeight = $request->raw_weight;
            
            if ($request->item_type === 'single') {
                // For single items, check against the latest scrap weight
                $scrapWeight = $Item->getLatestScrapWeight();
                
                if ($scrapWeight > $rawWeight) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Raw weight cannot be less than scrap weight. Current scrap weight is ' . $scrapWeight,
                        'errors' => ['raw_weight' => ['Raw weight cannot be less than scrap weight. Current scrap weight is ' . $scrapWeight]],
                    ], 200);
                }
            } else if ($request->item_type === 'grouped') {
                // For grouped items, get all child items and check highest scrap weight
                // Assuming there's a relationship to get child items
                // This is a placeholder - you'll need to implement the actual relationship method
                $childItems = $Item->childItems()->get();
                $highestScrapWeight = 0;
                
                foreach ($childItems as $childItem) {
                    $childScrapWeight = $childItem->getLatestScrapWeight();
                    if ($childScrapWeight > $highestScrapWeight) {
                        $highestScrapWeight = $childScrapWeight;
                    }
                }
                
                if ($highestScrapWeight > $rawWeight) {
                    return response()->json([
                        'status' => 422,
                        'message' => 'Raw weight cannot be less than the highest finished item scrap weight. Highest scrap weight is ' . $highestScrapWeight,
                        'errors' => ['raw_weight' => ['Raw weight cannot be less than the highest finished item scrap weight. Highest scrap weight is ' . $highestScrapWeight]],
                    ], 200);
                }
            }
        }


        // Check for unique combination - fixed to properly handle party_id as array
        $existingItemQuery = Item::where('name', $request->name)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->where('category_id', $request->category_id)
            ->where('item_code', $request->item_code)
            ->where('id', '!=', $id);
        
        // We need to check if the arrays have the same content, not by direct comparison
        $existingItem = $existingItemQuery->first();
        
        // If an item exists, check if the party_ids are the same
        if ($existingItem) {
            $existingPartyIds = $existingItem->party_id;
            $requestPartyIds = $request->party_id;
            
            // Sort both arrays to ensure proper comparison
            sort($existingPartyIds);
            sort($requestPartyIds);
            
            if ($existingPartyIds == $requestPartyIds) {
                return response()->json([
                    'status' => 409,
                    'message' => 'Item already exists for the given combination of party',
                ], 409);
            }
        }

        // Prepare common data for both item types
        $itemData = [
            'name' => $request->filled('name') ? $request->name : $Item->name,
            'item_type' => $Item->item_type,
            'category_id' => $request->filled('category_id') ? $request->category_id : $Item->category_id,
            'party_id' => $request->filled('party_id') ? $request->party_id : $Item->party_id,
            'material_price' => $request->filled('material_price') ? $request->material_price : $Item->material_price,
            'raw_weight' => $request->filled('raw_weight') ? $request->raw_weight : $Item->raw_weight,
            'gst_percent_rate' => $request->filled('gst_percent_rate') ? $request->gst_percent_rate : $Item->gst_percent_rate,
            'hsn' => $request->filled('hsn') ? $request->hsn : $Item->hsn,
            'item_code' => $request->filled('item_code') ? $request->item_code : $Item->item_code,
            'description' => $request->filled('description') ? $request->description : $Item->description,
            'tenant_id' => $tenantId,
            'company_id' => $activeCompanyId,
            'status' => 'active',
        ];

        // Add fields specific to single item type
        if ($request->item_type === 'single') {
            $itemData['job_work_rate'] = null;
            $itemData['finished_weight'] = $request->filled('finished_weight') ? $request->finished_weight : $Item->finished_weight;
            $itemData['scrap_weight'] = null;
        } else {
            // For grouped items, set these fields to null
            $itemData['job_work_rate'] = null;
            $itemData['finished_weight'] = null;
            $itemData['scrap_weight'] = null;
        }

        // Update the item
        $Item->update($itemData);

        return response()->json([
            'status' => 200,
            'message' => 'Item updated successfully',
            'record' => $Item,
        ]);
    }
   

    // Delete a product
    // public function destroy($id, Request $request)
    // {
    //     $response = $this->checkPermission('Item-Delete');
    
    //     // If checkPermission returns a response (i.e., permission denied), return it.
    //     if ($response) {
    //         return $response;
    //     }

    //     $user = $request->user();
    //     $tenantId = $user->tenant_id;
    //     $activeCompanyId = $user->getActiveCompanyId();

    //     $product = Product::where('id', $id)
    //         ->where('tenant_id', $tenantId)
    //         ->where('company_id', $activeCompanyId)
    //         ->first();

    //     if (!$product) {
    //         return response()->json([
    //             'status' => 200,
    //             'message' => 'Product not found',
    //         ], 200);
    //     }



    //     $product->delete();



    //     return response()->json([
    //         'status' => 200,
    //         'message' => 'Product deleted successfully',
    //     ]);
    // }

    //Item soft-delete
    public function destroy($id, Request $request)
    {
        $response = $this->checkPermission('Item-Delete');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $Item =Item::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();

        if (!$Item) {
            return response()->json([
                'status' => 200,
                'message' => 'Item not found',
            ], 200);
        }

        

        // $Item->update(['deleted_at' => now()]);

        if ($Item->item_type === 'single') {
            $Item->update(['deleted_at' => now()]);

        } else {
            $childItems = Item::where('parent_id', $Item->id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->get();

            foreach ($childItems as $child) {
               $child->update(['deleted_at' => now()]);
            }
            $Item->update(['deleted_at' => now()]);

        }

        return response()->json([
            'status' => 200,
            'message' => 'Item deleted successfully',
        ]);
    }

    //restore Item
    public function restore($id, Request $request)
    {
        // $response = $this->checkPermission('Item-Restore');
    
        // // If checkPermission returns a response (i.e., permission denied), return it.
        // if ($response) {
        //     return $response;
        // }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $Item = Item::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->first();

        if (!$Item) {
            return response()->json([
                'status' => 200,
                'message' => 'Item not found',
            ], 200);
        }

        

        //$Item->update(['deleted_at' => null]);

        if ($Item->item_type === 'single') {
            $Item->update(['deleted_at' => null]);

        } else {
            $childItems = Item::where('parent_id', $Item->id)
                ->where('tenant_id', $tenantId)
                ->where('company_id', $activeCompanyId)
                ->get();

            foreach ($childItems as $child) {
               $child->update(['deleted_at' => null]);
            }
            $Item->update(['deleted_at' => null]);

        }


        return response()->json([
            'status' => 200,
            'message' => 'Item restored successfully',
        ]);
    }

    public function index(Request $request)
    {
        $response = $this->checkPermission('Item-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Fetch query parameters
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $partyId = $request->input('party_id');
        $perPage = $request->input('per_page', 10); // Default to 10 items per page
    
        // Base query
        $query = Item::with('company', 'category')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)->whereNull('parent_id')->whereNull('deleted_at');
    
        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('item_code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhere('material_price', 'LIKE', "%{$search}%")
                    ->orWhereHas('category', function ($q2) use ($search) {
                        $q2->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('party', function ($q3) use ($search) {
                        $q3->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }
    
        // Apply category filter
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
    
        // Apply party filter
        if ($partyId) {
            $query->whereRaw('JSON_CONTAINS(party_id, ?)', [$partyId]);
        }
    
        // Apply pagination
        $Items = $query->paginate($perPage);

         // Map through Items to include party attribute dynamically
        $records = $Items->map(function ($Item) {
            $Item->party = $Item->getPartyAttribute();
            $Item->job_work_rate = $Item->getLatestJobworkRate();
            $Item->scrap_weight = $Item->getLatestScrapWeight();
            return $Item;
        });
    
        return response()->json([
            'status' => 200,
            'message' => 'Items retrieved successfully',
            'records' =>  $records, // Paginated records
            'pagination' => [
                'current_page' => $Items->currentPage(),
                'total_count' => $Items->total(),
                'per_page' => $Items->perPage(),
                'last_page' => $Items->lastPage(),
            ],
        ]);
    }

    public function indextrash(Request $request)
    {
        $response = $this->checkPermission('Item-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }

        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        // Fetch query parameters
        $search = $request->input('search');
        $categoryId = $request->input('category_id');
        $partyId = $request->input('party_id');
        $perPage = $request->input('per_page', 10); // Default to 10 items per page
    
        // Base query
        $query = Item::with('company', 'category')
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)->whereNull('parent_id')->whereNotNull('deleted_at');
    
        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('item_code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhere('material_price', 'LIKE', "%{$search}%")
                    ->orWhereHas('category', function ($q2) use ($search) {
                        $q2->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('party', function ($q3) use ($search) {
                        $q3->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }
    
        // Apply category filter
        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }
    
        // Apply party filter
        if ($partyId) {
            $query->whereRaw('JSON_CONTAINS(party_id, ?)', [$partyId]);
        }
    
        // Apply pagination
        $Items = $query->paginate($perPage);

         // Map through Items to include party attribute dynamically
        $records = $Items->map(function ($Item) {
            $Item->party = $Item->getPartyAttribute();
            return $Item;
        });
    
        return response()->json([
            'status' => 200,
            'message' => 'Items retrieved successfully',
            'records' =>  $records, // Paginated records
            'pagination' => [
                'current_page' => $Items->currentPage(),
                'total_count' => $Items->total(),
                'per_page' => $Items->perPage(),
                'last_page' => $Items->lastPage(),
            ],
        ]);
    }
    

    // Retrieve a single Item
    public function show($id, Request $request)
    {
        $response = $this->checkPermission('Item-Show');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();

        $Item = Item::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('company_id', $activeCompanyId)
            ->whereNull('deleted_at')
            ->first();


        if (!$Item) {
            return response()->json([
                'status' => 200,
                'message' => 'Item not found',
            ], 200);
        }

        $Item_party=$Item->getPartyAttribute();
        // Get item meta data grouped by type
        $itemMeta = $Item->getItemMetaByType();

       // Get all finished products that belong to this item, ensuring they have the correct tenant and company
        $finishedProducts = Item::where('parent_id', $id)
        ->where('tenant_id', $tenantId)
        ->where('company_id', $activeCompanyId)
        ->whereNull('deleted_at')
        ->get();

        return response()->json([
            'status' => 200,
            'message' => 'Item retrieved successfully',
            'record' => $Item,
            'party' => $Item_party,
            'itemMeta' =>$itemMeta,
            'finishedProducts' =>$finishedProducts
        ]);
    }

    public function changeStatus(Request $request, $id)
    {
        $response = $this->checkPermission('Item-Update');
    
        // If checkPermission returns a response (i.e., permission denied), return it.
        if ($response) {
            return $response;
        }
        
        $user = $request->user();
        $activeCompanyId = $user->getActiveCompanyId();
        // Validate the input
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'error' => $validator->errors(),
            ], 422);
        }

        try {
            // Get the authenticated user's tenant ID
            $tenantId = $request->user()->tenant_id;

            // Find the Item for the tenant
            $Item = Item::where('tenant_id', $tenantId)->where('company_id', $activeCompanyId)->find($id);

            if (!$Item) {
                return response()->json([
                    'status' => 200,
                    'message' => 'Item not found',
                ], 200);
            }

            if ($Item->item_type === 'single') {
                $Item->status = $request->status;
                $Item->save();
    
            } else {
                $childItems = Item::where('parent_id', $Item->id)
                    ->where('tenant_id', $tenantId)
                    ->where('company_id', $activeCompanyId)
                    ->get();
    
                foreach ($childItems as $child) {
                   $child->status = $request->status;
                   $child->save();
                }
                $Item->status = $request->status;
                $Item->save();

            }

            return response()->json([
                'status' => 200,
                'message' => 'Item status updated successfully',
                'record' =>  $Item,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Error updating Item status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateItemMetaparameters(Request $request)
    {
        $response = $this->checkPermission('Item-Update');
        if ($response) {
            return $response;
        }
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        $validator = Validator::make($request->all(), [
            'item_meta_id' => [
                'required',
                'integer',
                Rule::exists('item_meta', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->whereRaw('EXISTS (SELECT 1 FROM item WHERE item.id = item_meta.item_id AND item.tenant_id = ? AND item.company_id = ? AND item.deleted_at IS NULL)', [$tenantId, $activeCompanyId]);
                }),
            ],
            'Parameter' => 'nullable|string|max:255',
            'Specification' => 'nullable|string|max:255',
            'spl_chs' => 'nullable|string|max:255',
            'inspection_method' => 'nullable|string|max:255',
            'amendment_date' => 'nullable|date',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Find the item meta record
        $itemMeta = Itemmeta::where('id', $request->item_meta_id)
        ->where('item_meta_type', 'parameters')
        ->first();
        if (!$itemMeta) {
           return response()->json([
                    'status' => 200,
                    'message' => 'Item not found',
                ], 200);
            }
        
        $itemMeta->update([
            'Parameter' => $request->filled('Parameter') ? $request->Parameter : $itemMeta->Parameter,
            'Specification' => $request->filled('Specification') ? $request->Specification : $itemMeta->Specification,
            'spl_chs' => $request->filled('spl_chs') ? $request->spl_chs : $itemMeta->spl_chs,
            'inspection_method' => $request->filled('inspection_method') ? $request->inspection_method : $itemMeta->inspection_method,
            'amendment_date' => $request->filled('amendment_date') ? $request->amendment_date : $itemMeta->amendment_date
        ]);
    
        return response()->json([
            'status' => 200,
            'message' => 'Meta data updated successfully',
            'item_type' => $itemMeta->item_meta_type,
            'meta' => $itemMeta
        ]);
    }

    public function getItemMetaparameters(Request $request)
    {
        $response = $this->checkPermission('Item-Show');
        if ($response) {
            return $response;
        }
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        $validator = Validator::make($request->all(), [
            'item_meta_id' => [
                'required',
                'integer',
                Rule::exists('item_meta', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->whereRaw('EXISTS (SELECT 1 FROM item WHERE item.id = item_meta.item_id AND item.tenant_id = ? AND item.company_id = ? AND item.deleted_at IS NULL)', [$tenantId, $activeCompanyId]);
                }),
            ]  
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Find the item meta record
        $itemMeta = Itemmeta::where('id', $request->item_meta_id)
        ->where('item_meta_type', 'parameters')
        ->first();
        if (!$itemMeta) {
           return response()->json([
                    'status' => 200,
                    'message' => 'Item not found',
                ], 200);
            }
        
    
        return response()->json([
            'status' => 200,
            'message' => 'Meta data updated successfully',
            'item_type' => $itemMeta->item_meta_type,
            'meta' => $itemMeta
        ]);
    }

    public function deleteItemMetaparameters(Request $request)
    {
        $response = $this->checkPermission('Item-Delete');
        if ($response) {
            return $response;
        }
    
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $activeCompanyId = $user->getActiveCompanyId();
    
        $validator = Validator::make($request->all(), [
            'item_meta_id' => [
                'required',
                'integer',
                Rule::exists('item_meta', 'id')->where(function ($query) use ($tenantId, $activeCompanyId) {
                    $query->whereRaw('EXISTS (SELECT 1 FROM item WHERE item.id = item_meta.item_id AND item.tenant_id = ? AND item.company_id = ? AND item.deleted_at IS NULL)', [$tenantId, $activeCompanyId]);
                }),
            ]
            
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Find the item meta record
        $itemMeta = Itemmeta::where('id', $request->item_meta_id)
        ->where('item_meta_type', 'parameters')
        ->first();
        if (!$itemMeta) {
           return response()->json([
                    'status' => 200,
                    'message' => 'Item not found',
                ], 200);
            }
        
        $itemMeta->delete();
    
        return response()->json([
            'status' => 200,
            'message' => 'Meta data deleted successfully'
        ]);
    }


}
