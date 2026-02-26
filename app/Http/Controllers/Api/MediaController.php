<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class MediaController extends Controller
{
    use HandlesApiErrors;

    /**
     * Get list of media items.
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $companyId = $user->company_id ?? 'general';
            
            $mediaDir = 'media/' . $companyId;
            $fullPath = Storage::disk('public')->path($mediaDir);
            
            // Ensure directory exists
            if (!Storage::disk('public')->exists($mediaDir)) {
                Storage::disk('public')->makeDirectory($mediaDir);
            }
            
            // Get all files in the media directory
            $files = Storage::disk('public')->files($mediaDir);
            
            $mediaItems = [];
            foreach ($files as $file) {
                $path = $file;
                $url = Storage::disk('public')->url($path);
                
                // If the URL is relative (starts with /storage), make it absolute using APP_URL
                if (strpos($url, '/') === 0 && !filter_var($url, FILTER_VALIDATE_URL)) {
                    $baseUrl = rtrim(config('app.url'), '/');
                    $url = $baseUrl . $url;
                }
                
                // Final check: if still not a valid URL, use url() helper
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    $url = url($url);
                }
                
                $fileName = basename($path);
                $mimeType = Storage::disk('public')->mimeType($path);
                $isVideo = strpos($mimeType, 'video/') === 0;
                
                $mediaItems[] = [
                    'id' => count($mediaItems) + 1,
                    'url' => $url,
                    'name' => $fileName,
                    'size' => Storage::disk('public')->size($path),
                    'type' => $isVideo ? 'video' : 'image',
                    'mimeType' => $mimeType,
                    'created_at' => date('Y-m-d H:i:s', Storage::disk('public')->lastModified($path)),
                ];
            }
            
            // Sort by created_at descending
            usort($mediaItems, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            return response()->json($mediaItems);
        } catch (\Exception $e) {
            Log::error('Failed to fetch media items', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null,
            ]);
            return response()->json([]);
        }
    }

    /**
     * Upload a media file.
     */
    public function upload(Request $request)
    {
        $user = $request->user();
        
        // Check if file was actually uploaded before validation
        if (!$request->hasFile('file')) {
            Log::error('No file received in upload request', [
                'user_id' => $user->id,
                'request_all' => $request->all(),
                'php_upload_errors' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads'),
                ],
            ]);
            
            return response()->json([
                'message' => 'No file was uploaded. Please check file size limits and try again.',
                'errors' => [
                    'file' => [
                        'The file failed to upload. This may be due to file size limits or server configuration.',
                    ],
                ],
                'server_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads'),
                ],
            ], 422);
        }

        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,mp4,webm,ogg,mov,avi|max:51200', // 50MB max for videos
            ]);

            $file = $request->file('file');
            $mediaDir = 'media/' . ($user->company_id ?? 'general');
            $fullPath = Storage::disk('public')->path($mediaDir);

            // Ensure directory exists and is writable
            if (!Storage::disk('public')->exists($mediaDir)) {
                Storage::disk('public')->makeDirectory($mediaDir, 0755, true);
            }
            
            // Check if directory is writable, if not try to fix permissions
            if (!is_writable($fullPath)) {
                // Try to make directory writable
                @chmod($fullPath, 0755);
                
                // Check again after attempting to fix
                if (!is_writable($fullPath)) {
                    Log::error('Media directory not writable', [
                        'path' => $fullPath,
                        'user_id' => $user->id,
                        'permissions' => substr(sprintf('%o', fileperms($fullPath)), -4),
                        'owner' => posix_getpwuid(fileowner($fullPath))['name'] ?? 'unknown',
                        'group' => posix_getgrgid(filegroup($fullPath))['name'] ?? 'unknown',
                    ]);
                    return response()->json([
                        'message' => 'Server storage directory is not writable. Please check permissions. ' .
                                    'Directory: ' . $fullPath . ' ' .
                                    'Permissions: ' . substr(sprintf('%o', fileperms($fullPath)), -4),
                    ], 500);
                }
            }

            $path = $file->store($mediaDir, 'public');
            
            // Generate full URL - ensure it's always absolute
            $imageUrl = Storage::disk('public')->url($path);
            
            // If the URL is relative (starts with /storage), make it absolute using APP_URL
            if (strpos($imageUrl, '/') === 0 && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $baseUrl = rtrim(config('app.url'), '/');
                $imageUrl = $baseUrl . $imageUrl;
            }
            
            // Final check: if still not a valid URL, use url() helper
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = url($imageUrl);
            }

            Log::info('Media uploaded', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'path' => $path,
                'url' => $imageUrl,
            ]);

            // Determine file type
            $mimeType = $file->getMimeType();
            $isVideo = strpos($mimeType, 'video/') === 0;
            
            return response()->json([
                'url' => $imageUrl,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'type' => $isVideo ? 'video' : 'image',
                'mimeType' => $mimeType,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Media upload validation failed', [
                'errors' => $e->errors(),
                'user_id' => $user->id,
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
                'server_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads'),
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Media upload failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to upload media: ' . $e->getMessage(),
                'server_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads'),
                ],
            ], 500);
        }
    }
}
