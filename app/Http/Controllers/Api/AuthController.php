<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\BrazilianPhoneValidation;
use App\Rules\CnhValidation;
use App\Rules\CpfValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
                'password' => ['required', 'confirmed', Password::defaults()],
                'cpf' => ['required', 'string', new CpfValidation(), 'unique:users'],
                'rg' => ['required', 'string', 'max:20'],
                'cnh' => ['required', 'string', new CnhValidation(), 'unique:users'],
                'cnh_category' => ['required', 'string', 'in:A,B,C,D,E,AB,AC,AD,AE'],
                'cnh_expiry_date' => ['required', 'date', 'after:today'],
                'phone' => ['required', 'string', new BrazilianPhoneValidation()],
                'whatsapp' => ['nullable', 'string', new BrazilianPhoneValidation()],
                'birth_date' => ['required', 'date', 'before:18 years ago'],
                'address' => ['required', 'string', 'max:500'],
                'city' => ['required', 'string', 'max:100'],
                'state' => ['required', 'string', 'size:2'],
                'zip_code' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dados de validação inválidos.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'cpf' => preg_replace('/[^0-9]/', '', $request->cpf),
                'rg' => $request->rg,
                'cnh' => preg_replace('/[^0-9]/', '', $request->cnh),
                'cnh_category' => $request->cnh_category,
                'cnh_expiry_date' => $request->cnh_expiry_date,
                'phone' => preg_replace('/[^0-9]/', '', $request->phone),
                'whatsapp' => $request->whatsapp ? preg_replace('/[^0-9]/', '', $request->whatsapp) : null,
                'birth_date' => $request->birth_date,
                'address' => $request->address,
                'city' => $request->city,
                'state' => strtoupper($request->state),
                'zip_code' => preg_replace('/[^0-9]/', '', $request->zip_code),
                'role' => 'customer',
                'is_verified' => false,
            ]);

            $token = $user->createToken('auth_token', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Usuário registrado com sucesso.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_verified' => $user->is_verified,
                        'can_rent' => $user->canRent(),
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDays(30)->toISOString(),
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            ], 500);
        }
    }

    /**
     * Login user and return JWT token.
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'password' => ['required'],
                'device_name' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dados de validação inválidos.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Credenciais inválidas.',
                ], 401);
            }

            // Revoke existing tokens for this device
            $user->tokens()->where('name', $request->device_name)->delete();

            $token = $user->createToken($request->device_name, ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Login realizado com sucesso.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_verified' => $user->is_verified,
                        'can_rent' => $user->canRent(),
                        'has_active_rental' => $user->hasActiveRental(),
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDays(30)->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            ], 500);
        }
    }

    /**
     * Logout user and revoke token.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revoke the token that was used to authenticate the current request
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Logout realizado com sucesso.',
            ]);

        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            ], 500);
        }
    }

    /**
     * Get user profile information.
     */
    public function profile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'cpf' => $this->formatCpf($user->cpf),
                        'rg' => $user->rg,
                        'cnh' => $this->formatCnh($user->cnh),
                        'cnh_category' => $user->cnh_category,
                        'cnh_expiry_date' => $user->cnh_expiry_date?->format('Y-m-d'),
                        'phone' => $this->formatPhone($user->phone),
                        'whatsapp' => $user->whatsapp ? $this->formatPhone($user->whatsapp) : null,
                        'birth_date' => $user->birth_date?->format('Y-m-d'),
                        'address' => $user->address,
                        'city' => $user->city,
                        'state' => $user->state,
                        'zip_code' => $this->formatZipCode($user->zip_code),
                        'role' => $user->role,
                        'is_verified' => $user->is_verified,
                        'credit_limit' => $user->credit_limit,
                        'can_rent' => $user->canRent(),
                        'has_active_rental' => $user->hasActiveRental(),
                        'total_spent' => $user->getTotalSpentAttribute(),
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Profile error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            ], 500);
        }
    }

    /**
     * Refresh user token.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'device_name' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Dados de validação inválidos.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();

            // Revoke the current token
            $request->user()->currentAccessToken()->delete();

            // Create a new token
            $token = $user->createToken($request->device_name, ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Token renovado com sucesso.',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'expires_at' => now()->addDays(30)->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Token refresh error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            ], 500);
        }
    }

    /**
     * Revoke all user tokens.
     */
    public function revokeAllTokens(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $user->tokens()->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Todos os tokens foram revogados com sucesso.',
            ]);

        } catch (\Exception $e) {
            Log::error('Revoke all tokens error: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erro interno do servidor. Tente novamente mais tarde.',
            ], 500);
        }
    }

    /**
     * Format CPF for display.
     */
    private function formatCpf(?string $cpf): ?string
    {
        if (!$cpf || strlen($cpf) !== 11) {
            return $cpf;
        }

        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }

    /**
     * Format CNH for display.
     */
    private function formatCnh(?string $cnh): ?string
    {
        if (!$cnh || strlen($cnh) !== 11) {
            return $cnh;
        }

        return substr($cnh, 0, 3) . '.' . substr($cnh, 3, 3) . '.' . substr($cnh, 6, 3) . '-' . substr($cnh, 9, 2);
    }

    /**
     * Format phone for display.
     */
    private function formatPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        if (strlen($phone) === 11) {
            return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7, 4);
        }

        if (strlen($phone) === 10) {
            return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 4) . '-' . substr($phone, 6, 4);
        }

        return $phone;
    }

    /**
     * Format ZIP code for display.
     */
    private function formatZipCode(?string $zipCode): ?string
    {
        if (!$zipCode || strlen($zipCode) !== 8) {
            return $zipCode;
        }

        return substr($zipCode, 0, 5) . '-' . substr($zipCode, 5, 3);
    }
}