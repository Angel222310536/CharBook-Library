<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El middleware 'admin' ya filtra
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'synopsis' => ['nullable', 'string'],
            'author_id' => ['required', 'exists:authors,id'],

            // Subida de archivos
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],   // 5 MB
            'cover_url' => ['nullable', 'url', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,epub', 'max:51200'],             // 50 MB
            'file_url' => ['nullable', 'url', 'max:500'],

            'file_type' => ['required', 'in:pdf,epub,both'],

            // Monetización
            'access_type' => ['required', 'in:free,premium'],
            'price' => ['nullable', 'numeric', 'min:0', 'required_if:access_type,premium'],

            // Metadata
            'isbn_10' => ['nullable', 'string', 'max:13'],
            'isbn_13' => ['nullable', 'string', 'max:17'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'publication_year' => ['nullable', 'integer', 'between:-3000,2100'],
            'language' => ['nullable', 'string', 'size:2'],
            'pages' => ['nullable', 'integer', 'min:1'],

            // Categorías (multi-select)
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['exists:categories,id'],

            // Flags
            'is_published' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'author_id.required' => 'Debes seleccionar un autor.',
            'author_id.exists' => 'El autor seleccionado no existe.',
            'cover.mimes' => 'La portada debe ser JPG, PNG o WebP.',
            'cover.max' => 'La portada no puede pesar más de 5 MB.',
            'file.mimes' => 'El archivo debe ser PDF o EPUB.',
            'file.max' => 'El archivo no puede pesar más de 50 MB.',
            'price.required_if' => 'Los libros premium requieren un precio.',
        ];
    }

    /**
     * Normaliza booleanos que llegan como "true"/"false" desde FormData.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_published' => $this->boolean('is_published', true),
            'is_featured' => $this->boolean('is_featured', false),
        ]);

        // Parsea category_ids si viene como string CSV o JSON
        if (is_string($this->category_ids)) {
            $decoded = json_decode($this->category_ids, true);
            $this->merge([
                'category_ids' => is_array($decoded) ? $decoded : explode(',', $this->category_ids),
            ]);
        }
    }
}
