<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    /**
     * Return success response.
     */
    public function sendResponse($result = [], string $message = 'Request completed successfully.', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $result,
        ], $code);
    }

    /**
     * Return created response.
     */
    public function sendCreated($result = [], string $message = 'Created successfully.'): JsonResponse
    {
        return $this->sendResponse($result, $message, 201);
    }

    /**
     * Return error response.
     */
    public function sendError(string $error = 'Something went wrong.', $errorMessages = [], int $code = 404): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    /**
     * Return validation error response.
     */
    public function sendValidationError($errors, string $message = 'Validation failed.'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    /**
     * Return unauthorized response.
     */
    public function sendUnauthorized(string $message = 'Unauthorized access.'): JsonResponse
    {
        return $this->sendError($message, [], 401);
    }

    /**
     * Return forbidden response.
     */
    public function sendForbidden(string $message = 'You do not have permission to perform this action.'): JsonResponse
    {
        return $this->sendError($message, [], 403);
    }

    /**
     * Return not found response.
     */
    public function sendNotFound(string $message = 'Record not found.'): JsonResponse
    {
        return $this->sendError($message, [], 404);
    }

    /**
     * Return server error response.
     */
    public function sendServerError(string $message = 'Internal server error.'): JsonResponse
    {
        return $this->sendError($message, [], 500);
    }
}