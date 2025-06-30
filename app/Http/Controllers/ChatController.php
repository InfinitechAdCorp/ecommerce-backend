<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ChatController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get or create conversation for user
     */
    public function getConversation(Request $request)
    {
        try {
            $user = Auth::user();
            
            Log::info('Getting conversation for user', ['user_id' => $user->id]);
            
            // Get active conversation or create new one
            $conversation = ChatConversation::where('user_id', $user->id)
                ->where('status', 'active')
                ->with(['messages' => function($query) {
                    $query->with('user:id,name,email')
                        ->orderBy('created_at', 'asc');
                }, 'admin:id,name,email'])
                ->first();

            if (!$conversation) {
                Log::info('Creating new conversation for user', ['user_id' => $user->id]);
                
                $conversation = ChatConversation::create([
                    'user_id' => $user->id,
                    'status' => 'waiting',
                    'subject' => 'General Inquiry'
                ]);
                
                $conversation->load(['messages', 'admin:id,name,email']);
            }

            Log::info('Conversation retrieved successfully', ['conversation_id' => $conversation->id]);

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation' => $conversation,
                    'unread_count' => $conversation->unreadMessagesCount($user->id)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get conversation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get conversation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send message
     */
    public function sendMessage(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'conversation_id' => 'required|exists:chat_conversations,id',
                'message' => 'required|string|max:1000',
                'message_type' => 'sometimes|string|in:text,image,file'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $conversationId = $request->conversation_id;

            Log::info('Sending message', [
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'message_length' => strlen($request->message)
            ]);

            // Verify user owns this conversation or is admin - Allow admins to access any conversation
            $conversation = ChatConversation::where('id', $conversationId);

            // If user is not admin, restrict to their own conversations or assigned conversations
            if ($user->role !== 'admin') {
                $conversation = $conversation->where(function($query) use ($user) {
                    $query->where('user_id', $user->id)
                          ->orWhere('admin_id', $user->id);
                });
            }

            $conversation = $conversation->first();

            if (!$conversation) {
                Log::warning('Conversation access denied', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversationId
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found or access denied'
                ], 404);
            }

            // Create message
            $message = ChatMessage::create([
                'conversation_id' => $conversationId,
                'user_id' => $user->id,
                'message' => $request->message,
                'message_type' => $request->message_type ?? 'text',
                'metadata' => $request->metadata ?? null,
                'is_admin' => $user->role === 'admin' // Assuming you have role field
            ]);

            // Update conversation
            $conversation->update([
                'last_message_at' => now(),
                'status' => 'active'
            ]);

            // Load user relationship
            $message->load('user:id,name,email');

            Log::info('Message sent successfully', ['message_id' => $message->id]);

            return response()->json([
                'success' => true,
                'data' => $message
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages for conversation
     */
    public function getMessages(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();

            Log::info('Getting messages', [
                'user_id' => $user->id,
                'conversation_id' => $conversationId
            ]);

            // Verify access - Allow admins to access any conversation
            $conversation = ChatConversation::where('id', $conversationId);

            // If user is not admin, restrict to their own conversations or assigned conversations
            if ($user->role !== 'admin') {
                $conversation = $conversation->where(function($query) use ($user) {
                    $query->where('user_id', $user->id)
                          ->orWhere('admin_id', $user->id);
                });
            }

            $conversation = $conversation->first();

            if (!$conversation) {
                Log::warning('Messages access denied', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversationId
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found or access denied'
                ], 404);
            }

            $page = $request->get('page', 1);
            $limit = $request->get('limit', 50);

            $messages = ChatMessage::where('conversation_id', $conversationId)
                ->with('user:id,name,email')
                ->orderBy('created_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            // Mark messages as read
            ChatMessage::where('conversation_id', $conversationId)
                ->where('user_id', '!=', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            Log::info('Messages retrieved successfully', [
                'conversation_id' => $conversationId,
                'message_count' => $messages->count()
            ]);

            return response()->json([
                'success' => true,
                'data' => $messages
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get messages', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get messages: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Close conversation
     */
    public function closeConversation(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();

            Log::info('Closing conversation', [
                'user_id' => $user->id,
                'conversation_id' => $conversationId
            ]);

            $conversation = ChatConversation::where('id', $conversationId);

            // If user is not admin, restrict to their own conversations or assigned conversations
            if ($user->role !== 'admin') {
                $conversation = $conversation->where(function($query) use ($user) {
                    $query->where('user_id', $user->id)
                          ->orWhere('admin_id', $user->id);
                });
            }

            $conversation = $conversation->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found or access denied'
                ], 404);
            }

            $conversation->update(['status' => 'closed']);

            Log::info('Conversation closed successfully', ['conversation_id' => $conversationId]);

            return response()->json([
                'success' => true,
                'message' => 'Conversation closed successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to close conversation', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to close conversation: ' . $e->getMessage()
            ], 500);
        }
    }
}
