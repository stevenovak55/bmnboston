<?php
/**
 * WebSocket Server for Real-Time Collaboration
 * 
 * Enables real-time updates for property listings and user interactions
 * 
 * @package MLS_Listings_Display
 * @since 3.0.0
 */

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class MLD_WebSocket_Server implements MessageComponentInterface {
    
    protected $clients;
    protected $rooms;
    protected $user_connections;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->rooms = [];
        $this->user_connections = [];
    }
    
    /**
     * Handle new connection
     */
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        
        // Parse query string for authentication
        $query = $conn->httpRequest->getUri()->getQuery();
        parse_str($query, $params);
        
        if (isset($params['token'])) {
            $user_id = $this->validateToken($params['token']);
            if ($user_id) {
                $conn->user_id = $user_id;
                $this->user_connections[$user_id] = $conn;
                
                // Send connection confirmation
                $conn->send(json_encode([
                    'type' => 'connected',
                    'user_id' => $user_id,
                    'timestamp' => time()
                ]));
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("User {$user_id} connected via WebSocket");
                }
            } else {
                $conn->close();
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Invalid token - connection rejected");
                }
            }
        } else {
            // Allow anonymous connections for public features
            $conn->user_id = 'anonymous_' . uniqid();
            $conn->send(json_encode([
                'type' => 'connected',
                'user_id' => $conn->user_id,
                'anonymous' => true,
                'timestamp' => time()
            ]));
        }
    }
    
    /**
     * Handle incoming message
     */
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['action'])) {
            return;
        }
        
        switch ($data['action']) {
            case 'join_room':
                $this->joinRoom($from, $data['room']);
                break;
                
            case 'leave_room':
                $this->leaveRoom($from, $data['room']);
                break;
                
            case 'viewing_property':
                $this->handleViewingProperty($from, $data);
                break;
                
            case 'favorite_added':
                $this->handleFavoriteAdded($from, $data);
                break;
                
            case 'note_added':
                $this->handleNoteAdded($from, $data);
                break;
                
            case 'property_updated':
                $this->handlePropertyUpdated($from, $data);
                break;
                
            case 'user_typing':
                $this->handleUserTyping($from, $data);
                break;
                
            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                break;
        }
    }
    
    /**
     * Handle connection close
     */
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        
        // Remove from all rooms
        foreach ($this->rooms as $room => $clients) {
            $this->leaveRoom($conn, $room);
        }
        
        // Remove from user connections
        if (isset($conn->user_id) && isset($this->user_connections[$conn->user_id])) {
            unset($this->user_connections[$conn->user_id]);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Connection closed for user: " . ($conn->user_id ?? 'unknown'));
        }
    }
    
    /**
     * Handle connection error
     */
    public function onError(ConnectionInterface $conn, \Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WebSocket Error: " . $e->getMessage());
        }
        $conn->close();
    }
    
    /**
     * Join a room (property viewing session)
     */
    protected function joinRoom(ConnectionInterface $conn, $room) {
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }
        
        $this->rooms[$room][$conn->resourceId] = $conn;
        
        // Notify others in the room
        $this->broadcastToRoom($room, [
            'type' => 'user_joined',
            'user_id' => $conn->user_id,
            'room' => $room,
            'timestamp' => time()
        ], $conn);
        
        // Send current room participants to the new user
        $participants = [];
        foreach ($this->rooms[$room] as $client) {
            if (isset($client->user_id)) {
                $participants[] = $client->user_id;
            }
        }
        
        $conn->send(json_encode([
            'type' => 'room_joined',
            'room' => $room,
            'participants' => $participants,
            'timestamp' => time()
        ]));
    }
    
    /**
     * Leave a room
     */
    protected function leaveRoom(ConnectionInterface $conn, $room) {
        if (isset($this->rooms[$room][$conn->resourceId])) {
            unset($this->rooms[$room][$conn->resourceId]);
            
            // Notify others in the room
            $this->broadcastToRoom($room, [
                'type' => 'user_left',
                'user_id' => $conn->user_id,
                'room' => $room,
                'timestamp' => time()
            ], $conn);
            
            // Clean up empty rooms
            if (empty($this->rooms[$room])) {
                unset($this->rooms[$room]);
            }
        }
    }
    
    /**
     * Handle user viewing a property
     */
    protected function handleViewingProperty(ConnectionInterface $from, $data) {
        $property_id = $data['property_id'] ?? null;
        
        if (!$property_id) {
            return;
        }
        
        // Join property room
        $room = "property_{$property_id}";
        $this->joinRoom($from, $room);
        
        // Track viewing activity
        $this->trackActivity('property_view', [
            'user_id' => $from->user_id,
            'property_id' => $property_id,
            'timestamp' => time()
        ]);
        
        // Broadcast to others viewing the same property
        $this->broadcastToRoom($room, [
            'type' => 'viewer_activity',
            'user_id' => $from->user_id,
            'action' => 'viewing',
            'property_id' => $property_id,
            'timestamp' => time()
        ], $from);
    }
    
    /**
     * Handle favorite added
     */
    protected function handleFavoriteAdded(ConnectionInterface $from, $data) {
        $property_id = $data['property_id'] ?? null;
        
        if (!$property_id) {
            return;
        }
        
        // Broadcast to property room
        $room = "property_{$property_id}";
        $this->broadcastToRoom($room, [
            'type' => 'favorite_activity',
            'user_id' => $from->user_id,
            'property_id' => $property_id,
            'action' => 'added',
            'timestamp' => time()
        ], $from);
        
        // Track activity
        $this->trackActivity('favorite_added', [
            'user_id' => $from->user_id,
            'property_id' => $property_id,
            'timestamp' => time()
        ]);
    }
    
    /**
     * Handle note added to property
     */
    protected function handleNoteAdded(ConnectionInterface $from, $data) {
        $property_id = $data['property_id'] ?? null;
        $note = $data['note'] ?? '';
        
        if (!$property_id || !$note) {
            return;
        }
        
        // Broadcast to property room
        $room = "property_{$property_id}";
        $this->broadcastToRoom($room, [
            'type' => 'note_added',
            'user_id' => $from->user_id,
            'property_id' => $property_id,
            'note' => $note,
            'timestamp' => time()
        ]);
    }
    
    /**
     * Handle property update notifications
     */
    protected function handlePropertyUpdated(ConnectionInterface $from, $data) {
        $property_id = $data['property_id'] ?? null;
        $update_type = $data['update_type'] ?? 'general';
        $details = $data['details'] ?? [];
        
        if (!$property_id) {
            return;
        }
        
        // Broadcast to all clients interested in this property
        $room = "property_{$property_id}";
        $this->broadcastToRoom($room, [
            'type' => 'property_updated',
            'property_id' => $property_id,
            'update_type' => $update_type,
            'details' => $details,
            'timestamp' => time()
        ]);
        
        // Also notify users who have favorited this property
        $this->notifyFavoritedUsers($property_id, $update_type, $details);
    }
    
    /**
     * Handle user typing indicator
     */
    protected function handleUserTyping(ConnectionInterface $from, $data) {
        $property_id = $data['property_id'] ?? null;
        $is_typing = $data['typing'] ?? false;
        
        if (!$property_id) {
            return;
        }
        
        $room = "property_{$property_id}";
        $this->broadcastToRoom($room, [
            'type' => 'user_typing',
            'user_id' => $from->user_id,
            'property_id' => $property_id,
            'typing' => $is_typing,
            'timestamp' => time()
        ], $from);
    }
    
    /**
     * Broadcast message to room
     */
    protected function broadcastToRoom($room, $data, $exclude = null) {
        if (!isset($this->rooms[$room])) {
            return;
        }
        
        $message = json_encode($data);
        
        foreach ($this->rooms[$room] as $client) {
            if ($exclude === null || $client !== $exclude) {
                $client->send($message);
            }
        }
    }
    
    /**
     * Broadcast to all connected clients
     */
    protected function broadcastToAll($data, $exclude = null) {
        $message = json_encode($data);
        
        foreach ($this->clients as $client) {
            if ($exclude === null || $client !== $exclude) {
                $client->send($message);
            }
        }
    }
    
    /**
     * Send message to specific user
     */
    protected function sendToUser($user_id, $data) {
        if (isset($this->user_connections[$user_id])) {
            $this->user_connections[$user_id]->send(json_encode($data));
        }
    }
    
    /**
     * Validate authentication token
     */
    protected function validateToken($token) {
        // Implement token validation logic
        // This should verify the token against your authentication system
        
        // For now, simple validation
        if (strlen($token) > 10) {
            // Extract user ID from token (implement your logic)
            return substr($token, 0, 10);
        }
        
        return false;
    }
    
    /**
     * Track user activity
     */
    protected function trackActivity($type, $data) {
        // Store activity in database for analytics
        global $wpdb;
        
        $table = $wpdb->prefix . 'mld_realtime_activity';
        $wpdb->insert($table, [
            'activity_type' => $type,
            'user_id' => $data['user_id'] ?? null,
            'property_id' => $data['property_id'] ?? null,
            'details' => json_encode($data),
            'created_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Notify users who favorited a property
     */
    protected function notifyFavoritedUsers($property_id, $update_type, $details) {
        global $wpdb;
        
        // Get users who favorited this property
        $table = $wpdb->prefix . 'mld_user_favorites';
        $users = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE property_id = %d",
            $property_id
        ));
        
        foreach ($users as $user_id) {
            $this->sendToUser($user_id, [
                'type' => 'favorite_property_updated',
                'property_id' => $property_id,
                'update_type' => $update_type,
                'details' => $details,
                'timestamp' => time()
            ]);
        }
    }
}

/**
 * Start WebSocket Server
 * Run this as a separate process: php websocket-server.php
 */
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    
    $port = 8080;
    
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new MLD_WebSocket_Server()
            )
        ),
        $port
    );
    
    echo "WebSocket server started on port {$port}\n";
    $server->run();
}