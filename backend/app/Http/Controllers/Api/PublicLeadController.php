<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Course;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicLeadController extends Controller
{
    public function store(
        Request $request,
        Center $center,
        Course $course
    ): JsonResponse {

        // Only active centers can receive public leads.
        abort_unless($center->isActive(), 404);

        // Protect against cross-tenant course access.
        abort_unless(
            (int) $course->center_id === (int) $center->id,
            404
        );

        // Only published courses can receive public requests.
        abort_unless($course->isPublished(), 404);

        $course->loadMissing([
            'language',
            'level',
        ]);

        abort_unless(
            $course->language !== null
            && $course->language->is_active
            && $course->level !== null
            && $course->level->is_active,
            404
        );

        // Honeypot: silently accept automated submissions without saving.
        if ($request->filled('website')) {
            return response()->json([
                'message' => 'Your request has been received.',
            ], 202);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:150',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
                'regex:/^\+?[0-9\s().-]{7,30}$/',
                'required_without:email',
                'required_if:preferred_contact_method,phone,whatsapp',
            ],

            'email' => [
                'nullable',
                'email:rfc',
                'max:254',
                'required_without:phone',
                'required_if:preferred_contact_method,email',
            ],

            'preferred_contact_method' => [
                'nullable',
                Rule::in([
                    'phone',
                    'whatsapp',
                    'email',
                ]),
            ],

            'message' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'privacy_notice_accepted' => [
                'required',
                'accepted',
            ],
        ]);

        $phone = isset($validated['phone'])
            ? trim($validated['phone'])
            : null;

        $email = isset($validated['email'])
            ? mb_strtolower(trim($validated['email']))
            : null;

        $contactMethod =
            $validated['preferred_contact_method']
            ?? ($phone ? 'phone' : 'email');

        // Create the lead using trusted route-bound identifiers.
        Lead::query()->create([
            'center_id' => $center->id,

            'course_id' => $course->id,

            'assigned_to_user_id' => null,

            'name' => trim($validated['name']),

            'phone' => $phone,

            'email' => $email,

            'preferred_contact_method' => $contactMethod,

            'message' => isset($validated['message'])
                ? trim($validated['message'])
                : null,

            'source' => Lead::SOURCE_WEBSITE,

            'source_detail' => 'course_details_page',

            'status' => Lead::STATUS_NEW,

            'privacy_notice_accepted_at' => now(),
        ]);

        // Never expose internal lead IDs or private contact data.
        return response()->json([
            'message' => 'Your request has been received successfully.',

            'data' => [
                'status' => Lead::STATUS_NEW,
            ],
        ], 201);
    }
}
