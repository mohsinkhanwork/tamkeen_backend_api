<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMemberShipRequest;
use App\Http\Requests\UpdateMemberShipRequest;
use App\Models\Coupon;
use App\Models\CouponPackageUser;
use App\Models\MembershipLevel;
use Illuminate\Http\Request;
use App\Services\CouponService;
use Illuminate\Support\Facades\Storage;
use App\Models\ReferralProfit;

class MembershipLevelController extends Controller
{
  
    public function index()
    {
        $membershipLevels = MembershipLevel::select('id', 'level_name', 'monthly_fee', 'description', 'condition_1', 'condition_2', 'icon')->get();
        foreach ($membershipLevels as $membershipLevel) {
            $membershipLevel->users_count = $membershipLevel->users()->count();
        }
        return response()->json($membershipLevels);
    }

    public function store(StoreMemberShipRequest $request)
    {
        // Initialize data array with validated request data
        $data = $request->validated();

        // Check if 'icon' (base64 image) is provided
        if ($request->has('icon')) {
            $base64Image = $request->icon;

            // Remove the base64 header if present
            $base64Image = preg_replace('/^data:image\/\w+;base64,/', '', $base64Image);

            // Decode the base64 image data
            $imageData = base64_decode($base64Image);

            // Determine the MIME type and file extension (default to 'png')
            $imageType = finfo_buffer(finfo_open(), $imageData, FILEINFO_MIME_TYPE);
            $extension = str_replace('image/', '', $imageType) ?: 'png';
            $imageName = uniqid() . '.' . $extension;
            $path = "membership_icon/{$imageName}";

            // Store the decoded image in 'public/packages' directory
            Storage::disk('public')->put($path, $imageData);

            // Save the path in the data array for storage in the database
            $data['icon'] = "storage/" . $path;
        }

        // Create the membership level record with the updated data
        $membershipLevel = MembershipLevel::create($data);

        // Return a JSON response with the newly created record
        return response()->json($membershipLevel, 201);
    }



    public function show(MembershipLevel $membershipLevel)
    {
        return response()->json($membershipLevel);
    }

   
    public function update(UpdateMemberShipRequest $request, MembershipLevel $membershipLevel)
    {
        $membershipLevel->update($request->validated());
        return response()->json($membershipLevel, 200);
    }

   
    public function destroy(MembershipLevel $membershipLevel)
    {
        $membershipLevel->delete();
        return response()->json(['message'=>'Membership level deleted successfully'], 200);
    }


    public function subscribe(MembershipLevel $membershipLevel, Request $request, CouponService $couponService)
    {
        $user = auth()->user();
        $membershipLevel_id = $membershipLevel->id;
        $couponCode = $request->input('coupon_code');
        $totalPrice = $membershipLevel->monthly_fee;

        // Check if the user is already subscribed to this membership level
        if ($user->membership_levels()->where('membership_level_id', $membershipLevel_id)->exists()) {
            return response()->json([
                'message' => 'You are already subscribed to this membership level.',
            ], 400);
        }

        try {
            // If a coupon code is provided, apply the coupon
            if ($couponCode) {
                $couponData = $couponService->applyCoupon($couponCode, $totalPrice);

                CouponPackageUser::create([
                    'coupon_id' => $couponData['coupon']->id,
                    'package_id' => $membershipLevel_id,
                    'user_id' => $user->id,
                    'price_of_package_after_coupon' => $couponData['discounted_price'],
                    'price_of_package_before_coupon' => $totalPrice,
                ]);

                $totalPrice = $couponData['discounted_price'];
            }

            // Attach membership level to the user
            $user->membership_levels()->attach($membershipLevel_id, ['is_subscribed' => true]);

            return response()->json([
                'message' => 'Subscription successful',
                'membership_level' => $membershipLevel->level_name,
                'total_price' => $totalPrice,
                'discount_applied' => $couponCode ? true : false,
                'discount_amount' => $couponCode ? $couponData['discount_amount'] : 0,
                
            ], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function getDetails(MembershipLevel $membershipLevel)
    {
         $authUser = auth()->user();
        if (!($authUser && ($authUser->hasRole('admin') ))) {
          return response()->json(['message' => 'Unauthorized'], 403);
        }

        $monthlyFee = $membershipLevel->monthly_fee; // Assuming the membership level has a monthly fee

        $users = $membershipLevel->users()
            ->withPivot('account', 'is_subscribed', 'activated_until', 'activated_from', 'is_active')
            ->wherePivot('is_subscribed', true)
            ->get()
            ->map(function ($user) use ($monthlyFee) {
                $pivot = $user->pivot;
                $amount = 0;

                // Condition 1: If account has a value and is_active is false, use the account value as the amount
                if ($pivot->account > 0 && !$pivot->is_active) {
                    $amount = $pivot->account;
                }

                // Condition 2 and 3: If there are valid activated_from and activated_until dates
                elseif ($pivot->activated_from && $pivot->activated_until) {
                    $activatedFrom = \Carbon\Carbon::parse($pivot->activated_from);
                    $activatedUntil = \Carbon\Carbon::parse($pivot->activated_until);

                    // Calculate the number of full months between the dates
                    $months = $activatedUntil->diffInMonths($activatedFrom);

                    // Check if the months are within the same year and month
                    if (
                        $activatedFrom->year != $activatedUntil->year ||
                        $activatedFrom->month != $activatedUntil->month
                    ) {
                        $months++; // Count both months as full if different
                    } else {
                        $months = 1; // If it's within the same month, count it as 1 month
                    }

                    // If account is 0 (Condition 2), use the months * monthly fee
                    if ($pivot->account == 0) {
                        $amount = $months * $monthlyFee;
                    }
                    // If account has a value (Condition 3), add it to the calculated months * monthly fee
                    else {
                        $amount = ($months * $monthlyFee) + $pivot->account;
                    }
                }

                // Add the calculated amount to the user object for the response
                $user->amount_paid = $amount;

                return $user;
            });

            foreach ($users as $user) {

          $profits =  ReferralProfit::where('referrer_id', $user->id)->where('membership_level_id', $membershipLevel->id)->sum('profit');

          $user->referral_profit = $profits;
            }
     

        return response()->json($users);
    }


    


}
