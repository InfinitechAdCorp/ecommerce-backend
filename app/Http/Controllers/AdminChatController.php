<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminChatController extends BaseController
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        // Add admin middleware if you have one
        // $this->middleware('admin');
    }

    /**
     * Get all conversations for admin
     */
    public function getConversations(Request $request)
    {
        try {
            $user = Auth::user();
            
            Log::info('Admin getting conversations', ['admin_id' => $user->id]);
            
            // Default to showing active AND waiting conversations (not closed)
            $status = $request->get('status', 'open'); // Changed default to 'open'
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 20);

            $conversations = ChatConversation::with([
                'user:id,name,email',
                'admin:id,name,email',
                'latestMessage'
            ]);

            // Filter conversations based on status
            if ($status === 'open') {
                // Show both active and waiting conversations (not closed)
                $conversations = $conversations->whereIn('status', ['active', 'waiting']);
            } elseif ($status !== 'all') {
                // Show specific status
                $conversations = $conversations->where('status', $status);
            }

            $conversations = $conversations->orderBy('last_message_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            // Add message counts and unread counts
            foreach ($conversations as $conversation) {
                $conversation->messages_count = $conversation->messages()->count();
                $conversation->unread_count = $conversation->unreadMessagesCount($user->id);
            }

            Log::info('Admin conversations retrieved successfully', [
                'admin_id' => $user->id,
                'conversation_count' => $conversations->count(),
                'status_filter' => $status
            ]);

            return response()->json([
                'success' => true,
                'data' => $conversations
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get admin conversations', [
                'admin_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get conversations: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign conversation to admin
     */
    public function assignConversation(Request $request, $conversationId)
    {
        try {
            $admin = Auth::user();
            
            Log::info('Assigning conversation to admin', [
                'admin_id' => $admin->id,
                'conversation_id' => $conversationId
            ]);
            
            $conversation = ChatConversation::find($conversationId);
            
            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            $conversation->update([
                'admin_id' => $admin->id,
                'status' => 'active'
            ]);

            Log::info('Conversation assigned successfully', [
                'admin_id' => $admin->id,
                'conversation_id' => $conversationId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Conversation assigned successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to assign conversation', [
                'admin_id' => $admin->id ?? null,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign conversation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * End/Close conversation - Enhanced with proper cleanup
     */
    public function endConversation(Request $request, $conversationId)
    {
        try {
            $admin = Auth::user();
            
            Log::info('Admin ending conversation', [
                'admin_id' => $admin->id,
                'conversation_id' => $conversationId
            ]);
            
            $conversation = ChatConversation::find($conversationId);
            
            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            // Update conversation status to closed
            $conversation->update([
                'status' => 'closed',
                'admin_id' => $admin->id, // Ensure admin is assigned
                'last_message_at' => now() // Update timestamp
            ]);

            // Add a system message indicating the chat was ended
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $admin->id,
                'message' => 'Chat ended by admin.',
                'message_type' => 'system',
                'is_admin' => true,
                'created_at' => now()
            ]);

            Log::info('Conversation ended successfully', [
                'admin_id' => $admin->id,
                'conversation_id' => $conversationId
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Chat ended successfully',
                'data' => [
                    'conversation_id' => $conversationId,
                    'status' => 'closed'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to end conversation', [
                'admin_id' => $admin->id ?? null,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to end conversation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get admin dashboard stats - Fixed to show accurate counts
     */
    public function getDashboardStats()
    {
        try {
            $admin = Auth::user();

            Log::info('Getting admin dashboard stats', ['admin_id' => $admin->id]);

            // Get accurate counts with proper filtering
            $totalConversations = ChatConversation::count();
            $activeConversations = ChatConversation::where('status', 'active')->count();
            $waitingConversations = ChatConversation::where('status', 'waiting')->count();
            $closedConversations = ChatConversation::where('status', 'closed')->count();
            $myConversations = ChatConversation::where('admin_id', $admin->id)
                ->whereIn('status', ['active', 'waiting']) // Only count open conversations
                ->count();
            $totalMessages = ChatMessage::where('message_type', '!=', 'system')->count(); // Exclude system messages
            
            // Count unread messages for this admin (only from open conversations)
            $unreadMessages = ChatMessage::whereHas('conversation', function($query) use ($admin) {
                $query->where('admin_id', $admin->id)
                      ->whereIn('status', ['active', 'waiting']); // Only open conversations
            })
            ->where('user_id', '!=', $admin->id)
            ->where('is_admin', false) // Only count customer messages
            ->where('message_type', '!=', 'system') // Exclude system messages
            ->whereNull('read_at')
            ->count();

            $stats = [
                'total_conversations' => $totalConversations,
                'active_conversations' => $activeConversations,
                'waiting_conversations' => $waitingConversations,
                'closed_conversations' => $closedConversations,
                'my_conversations' => $myConversations,
                'total_messages' => $totalMessages,
                'unread_messages' => $unreadMessages,
                // Add open conversations (active + waiting) for easier filtering
                'open_conversations' => $activeConversations + $waitingConversations,
                // Add today's stats
                'today_conversations' => ChatConversation::whereDate('created_at', today())->count(),
                'today_messages' => ChatMessage::whereDate('created_at', today())
                    ->where('message_type', '!=', 'system')
                    ->count()
            ];

            Log::info('Admin dashboard stats retrieved successfully', [
                'admin_id' => $admin->id,
                'stats' => $stats
            ]);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get admin dashboard stats', [
                'admin_id' => $admin->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to get stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update conversation status
     */
    public function updateConversationStatus(Request $request, $conversationId)
    {
        try {
            $admin = Auth::user();
            $status = $request->input('status');
            
            Log::info('Updating conversation status', [
                'admin_id' => $admin->id,
                'conversation_id' => $conversationId,
                'new_status' => $status
            ]);
            
            $conversation = ChatConversation::find($conversationId);
            
            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            $oldStatus = $conversation->status;
            
            $conversation->update([
                'status' => $status,
                'admin_id' => $admin->id,
                'last_message_at' => now()
            ]);

            // Add system message for status changes
            if ($oldStatus !== $status) {
                $statusMessage = match($status) {
                    'active' => 'Chat activated by admin.',
                    'closed' => 'Chat closed by admin.',
                    'waiting' => 'Chat moved to waiting queue.',
                    default => "Chat status changed to {$status}."
                };

                ChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $admin->id,
                    'message' => $statusMessage,
                    'message_type' => 'system',
                    'is_admin' => true,
                    'created_at' => now()
                ]);
            }

            Log::info('Conversation status updated successfully', [
                'admin_id' => $admin->id,
                'conversation_id' => $conversationId,
                'old_status' => $oldStatus,
                'new_status' => $status
            ]);

            return response()->json([
                'success' => true,
                'message' => "Conversation status updated to {$status}",
                'data' => [
                    'conversation_id' => $conversationId,
                    'old_status' => $oldStatus,
                    'new_status' => $status
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update conversation status', [
                'admin_id' => $admin->id ?? null,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update conversation status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug method to check conversation access
     */
    public function debugConversation(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            
            $conversation = ChatConversation::with(['user', 'admin'])->find($conversationId);
            
            $debug = [
                'conversation_exists' => $conversation ? true : false,
                'current_user' => [
                    'id' => $user->id,
                    'role' => $user->role ?? 'user',
                    'name' => $user->name,
                    'email' => $user->email
                ]
            ];
            
            if ($conversation) {
                $debug['conversation'] = [
                    'id' => $conversation->id,
                    'user_id' => $conversation->user_id,
                    'admin_id' => $conversation->admin_id,
                    'status' => $conversation->status,
                    'user' => $conversation->user ? [
                        'id' => $conversation->user->id,
                        'name' => $conversation->user->name,
                        'email' => $conversation->user->email
                    ] : null,
                    'admin' => $conversation->admin ? [
                        'id' => $conversation->admin->id,
                        'name' => $conversation->admin->name,
                        'email' => $conversation->admin->email
                    ] : null
                ];
                
                $debug['access_check'] = [
                    'is_conversation_owner' => $conversation->user_id == $user->id,
                    'is_assigned_admin' => $conversation->admin_id == $user->id,
                    'user_is_admin' => $user->role === 'admin'
                ];
            }
            
            return response()->json([
                'success' => true,
                'debug' => $debug
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => [
                    'user' => Auth::user()
                ]
            ]);
        }
    }
}
