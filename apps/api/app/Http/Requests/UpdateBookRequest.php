<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'synopsis' => ['nullable', 'string'],
            'author_id' => ['sometimes', 'required', 'exists:authors,id'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cover_url' => ['nullable', 'url', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,epub', 'max:51200'],
            'file_url' => ['nullable', 'url', 'max:500'],
            'file_type' => ['sometimes', 'in:pdf,epub,both'],
            'access_type' => ['sometimes', 'in:free,premium'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'isbn_10' => ['nullable', 'string', 'max:13'],
            'isbn_13' => ['nullable', 'string', 'max:17'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'publication_year' => ['nullable', 'integer', 'between:-3000,2100'],
            'language' => ['nullable', 'string', 'size:2'],
            'pages' => ['nullable', 'integer', 'min:1'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['exists:categories,id'],
            'is_published' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_published')) {
            $this->merge(['is_published' => $this->boolean('is_published')]);
        }
        if ($this->has('is_featured')) {
            $this->merge(['is_featured' => $this->boolean('is_featured')]);
        }
        if (is_string($this->category_ids)) {
            $decoded = json_decode($this->category_ids, true);
            $this->merge([
                'category_ids' => is_array($decoded) ? $decoded : explode(',', $this->category_ids),
            ]);
        }
    }
}
