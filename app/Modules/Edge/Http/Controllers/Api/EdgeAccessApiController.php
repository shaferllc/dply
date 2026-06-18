<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Modules\Edge\Http\Resources\EdgeAccessRuleResource;
use App\Modules\Edge\Services\EdgeAccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EdgeAccessApiController extends EdgeApiController
{
    public function show(Request $request, string $site): EdgeAccessRuleResource|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        if ($found->isEdgePreview()) {
            return response()->json([
                'message' => __('Configure preview protection on the parent Edge site.'),
            ], 422);
        }

        return new EdgeAccessRuleResource($found, $found->edgeSiteAccessRule);
    }

    public function update(Request $request, string $site): EdgeAccessRuleResource|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        if ($found->isEdgePreview()) {
            return response()->json([
                'message' => __('Configure preview protection on the parent Edge site.'),
            ], 422);
        }

        try {
            $data = $request->validate([
                'mode' => ['required', 'string', 'in:off,password,dply_account'],
                'password' => ['nullable', 'string', 'max:200'],
                'allowed_emails' => ['nullable', 'array', 'max:100'],
                'allowed_emails.*' => ['email', 'max:255'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $emails = is_array($data['allowed_emails'] ?? null) ? $data['allowed_emails'] : [];
        $password = isset($data['password']) ? trim((string) $data['password']) : null;
        if ($password === '') {
            $password = null;
        }

        try {
            $rule = app(EdgeAccessGate::class)->sync(
                $found->fresh(),
                (string) $data['mode'],
                $password,
                $emails,
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return new EdgeAccessRuleResource($found->fresh(), $rule);
    }
}
