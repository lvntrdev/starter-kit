<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseFormRequest extends FormRequest
{
    /**
     * Domain-specific translation namespace used to resolve validation
     * attribute names. Example: 'sk-user' reads flat string keys from
     * lang/{locale}/sk-user.php and uses them as :attribute replacements.
     * Keys declared here override the generic sk-attribute.attributes list.
     */
    protected string $attributeNamespace = '';

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $base = trans('sk-attribute.attributes');
        $base = is_array($base) ? array_filter($base, 'is_string') : [];

        if ($this->attributeNamespace === '') {
            return $base;
        }

        $domain = trans($this->attributeNamespace);
        $domain = is_array($domain) ? array_filter($domain, 'is_string') : [];

        return array_merge($base, $domain);
    }
}
