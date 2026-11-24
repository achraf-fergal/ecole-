<?php

namespace App\Http\Requests\Learning;

use App\Models\LessonAsset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExternalVideoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                'string',
                Rule::in([
                    LessonAsset::VIDEO_PROVIDER_YOUTUBE,
                    LessonAsset::VIDEO_PROVIDER_GOOGLE_DRIVE,
                ]),
            ],
            'url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:3000'],
            'asset_type' => ['prohibited'],
            'status' => ['prohibited'],
            'video_provider' => ['prohibited'],
            'provider_asset_id' => ['prohibited'],
            'external_url' => ['prohibited'],
            'storage_disk' => ['prohibited'],
            'storage_key' => ['prohibited'],
        ];
    }
}
