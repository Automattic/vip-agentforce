import { useCallback } from "react";

// Use WordPress REST API - relative URL works on any domain
const API_BASE_URL = "/wp-json/vip-agentforce/v1/custom-client";

interface MessagingCredentials {
  accessToken: string;
  conversationId: string;
  orgId?: string;
  lastEventId?: string;
}

interface MessagesResponse {
  conversationEntries?: ConversationEntry[];
}

interface ConversationEntry {
  identifier: string;
  entryType: string;
  entryPayload: string;
  sender: {
    role: string;
    displayName?: string;
  };
  senderDisplayName?: string;
  clientTimestamp: number;
}

interface MessagingHookReturn {
  initialize: () => Promise<MessagingCredentials>;
  sendMessage: (
    token: string,
    conversationId: string,
    content: string
  ) => Promise<void>;
  closeChat: (token: string, conversationId: string) => Promise<void>;
  getMessages: (
    token: string,
    conversationId: string
  ) => Promise<MessagesResponse>;
}

export function useSalesforceMessaging(): MessagingHookReturn {
  const initialize = useCallback(async (): Promise<MessagingCredentials> => {
    const response = await fetch(`${API_BASE_URL}/chat/initialize`);
    if (!response.ok) throw new Error("Failed to initialize chat");
    return response.json();
  }, []);

  const sendMessage = useCallback(
    async (
      token: string,
      conversationId: string,
      content: string
    ): Promise<void> => {
      const response = await fetch(`${API_BASE_URL}/chat/message`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "Content-Type": "application/json",
          "X-Conversation-Id": conversationId,
        },
        body: JSON.stringify({ message: content }),
      });

      if (!response.ok) throw new Error("Failed to send message");
    },
    []
  );

  const closeChat = useCallback(
    async (token: string, conversationId: string): Promise<void> => {
      const response = await fetch(`${API_BASE_URL}/chat/end`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${token}`,
          "X-Conversation-Id": conversationId,
        },
      });

      if (!response.ok) throw new Error("Failed to close chat");
    },
    []
  );

  const getMessages = useCallback(
    async (
      token: string,
      conversationId: string
    ): Promise<MessagesResponse> => {
      const response = await fetch(`${API_BASE_URL}/chat/message`, {
        headers: {
          Authorization: `Bearer ${token}`,
          "X-Conversation-Id": conversationId,
        },
      });

      if (!response.ok) throw new Error("Failed to get messages");
      return response.json();
    },
    []
  );

  return {
    initialize,
    sendMessage,
    closeChat,
    getMessages,
  };
}
