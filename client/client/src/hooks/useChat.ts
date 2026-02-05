import { useState, useEffect, useRef, useCallback } from "react";
import { Entry, Message } from "../types";
import { useSalesforceMessaging } from "./useSalesforceMessaging";

const INACTIVITY_TIMEOUT = 5 * 60 * 1000; // 5 minutes
const POLLING_INTERVAL = 2000; // 2 seconds
const DEBUG = import.meta.env.DEV; // Enable debug logging in development

export function useChat() {
  const [messages, setMessages] = useState<Message[]>([]);
  const [isConnected, setIsConnected] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [isTyping, setIsTyping] = useState(false);
  const [currentAgent, setCurrentAgent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const credsRef = useRef<{
    accessToken: string;
    conversationId: string;
  } | null>(null);
  const timeoutRef = useRef<NodeJS.Timeout | null>(null);
  const pollingRef = useRef<NodeJS.Timeout | null>(null);
  const processedMessageIdsRef = useRef<Set<string>>(new Set());
  const isInitializedRef = useRef(false);

  const {
    initialize,
    sendMessage: sendMessageToApi,
    closeChat: closeChatApi,
    getMessages,
  } = useSalesforceMessaging();

  const stopPolling = useCallback(() => {
    if (pollingRef.current) {
      clearInterval(pollingRef.current);
      pollingRef.current = null;
    }
  }, []);

  const resetTimeout = useCallback(() => {
    if (timeoutRef.current) clearTimeout(timeoutRef.current);

    timeoutRef.current = setTimeout(async () => {
      if (!credsRef.current || !isConnected) return;

      try {
        stopPolling();
        await closeChatApi(
          credsRef.current.accessToken,
          credsRef.current.conversationId
        );
        setIsConnected(false);
        setMessages((prev) => [
          ...prev,
          {
            id: crypto.randomUUID(),
            type: "system",
            content: "Chat ended due to inactivity",
            timestamp: new Date(),
          },
        ]);
      } catch (err) {
        console.error("Failed to end chat:", err);
      }
    }, INACTIVITY_TIMEOUT);
  }, [isConnected, closeChatApi, stopPolling]);

  // Process conversation entries from polling response
  const processConversationEntries = useCallback(
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (entries: any[]) => {
      if (DEBUG) {
        console.log("[useChat] Raw entries received:", entries);
      }

      // Sort by timestamp to process in order
      const sorted = [...entries].sort(
        (a, b) => a.clientTimestamp - b.clientTimestamp
      );

      sorted.forEach((entry) => {
        // Skip already processed entries
        if (processedMessageIdsRef.current.has(entry.identifier)) return;
        processedMessageIdsRef.current.add(entry.identifier);

        if (DEBUG) {
          console.log("[useChat] Processing entry:", entry.entryType, entry);
        }

        switch (entry.entryType) {
          case "Message":
            if (entry.sender?.role === "Chatbot") {
              try {
                const payload =
                  typeof entry.entryPayload === "string"
                    ? JSON.parse(entry.entryPayload)
                    : entry.entryPayload;

                // Safely extract message content with null checks
                const abstractMessage = payload?.abstractMessage;
                const messageText = abstractMessage?.staticContent?.text;
                const messageId = abstractMessage?.id || entry.identifier;

                if (DEBUG) {
                  console.log("[useChat] Chatbot message:", {
                    messageId,
                    messageText,
                    fullPayload: payload,
                  });
                }

                if (!messageText) {
                  console.warn("Message entry missing text content:", entry);
                  return;
                }

                // Extract agent name from message sender
                const senderName =
                  entry.senderDisplayName || entry.sender?.displayName;
                if (senderName) {
                  setCurrentAgent((prev) => prev || senderName);
                }

                setMessages((prev) => {
                  // Check if message already exists
                  if (prev.find((m) => m.id === messageId)) {
                    return prev;
                  }
                  return [
                    ...prev,
                    {
                      id: messageId,
                      type: "ai",
                      content: messageText,
                      timestamp: new Date(entry.clientTimestamp),
                    },
                  ];
                });
                setIsLoading(false);
                setIsTyping(false);
              } catch (err) {
                console.error("Failed to parse message payload:", err, entry);
              }
            }
            break;

          case "ParticipantChanged":
            try {
              const participantPayload =
                typeof entry.entryPayload === "string"
                  ? JSON.parse(entry.entryPayload)
                  : entry.entryPayload;

              participantPayload.entries?.forEach((p: Entry) => {
                if (
                  p.operation === "add" &&
                  p.participant?.role?.toLowerCase() === "chatbot"
                ) {
                  setCurrentAgent(p.displayName);
                  setMessages((prev) => [
                    ...prev,
                    {
                      id: crypto.randomUUID(),
                      type: "system",
                      content: `${p.displayName} has joined the chat`,
                      timestamp: new Date(),
                    },
                  ]);
                }
                if (
                  p.operation === "remove" &&
                  p.participant?.role === "agent"
                ) {
                  setCurrentAgent(null);
                  setMessages((prev) => [
                    ...prev,
                    {
                      id: crypto.randomUUID(),
                      type: "system",
                      content: `${p.displayName} has left the chat`,
                      timestamp: new Date(),
                    },
                  ]);
                }
              });
            } catch (err) {
              console.error("Failed to parse participant change:", err);
            }
            break;

          case "TypingStartedIndicator":
            setIsTyping(true);
            break;

          case "TypingStoppedIndicator":
            setIsTyping(false);
            break;
        }
      });
    },
    []
  );

  const startPolling = useCallback(() => {
    if (DEBUG) {
      console.log("[useChat] startPolling called, existing interval:", !!pollingRef.current);
    }

    if (pollingRef.current) {
      clearInterval(pollingRef.current);
    }

    setIsConnected(true);

    const poll = async () => {
      if (!credsRef.current) {
        if (DEBUG) {
          console.log("[useChat] poll: no creds, skipping");
        }
        return;
      }

      try {
        const data = await getMessages(
          credsRef.current.accessToken,
          credsRef.current.conversationId
        );

        if (data.conversationEntries && data.conversationEntries.length > 0) {
          processConversationEntries(data.conversationEntries);
        }

        resetTimeout();
      } catch (err) {
        console.error("Polling error:", err);
        // Check if conversation ended (404 or specific error)
        if (
          err instanceof Error &&
          (err.message.includes("404") || err.message.includes("ended"))
        ) {
          stopPolling();
          setIsConnected(false);
        }
      }
    };

    pollingRef.current = setInterval(poll, POLLING_INTERVAL);
    if (DEBUG) {
      console.log("[useChat] Polling interval set, ID:", pollingRef.current);
    }
    poll(); // Initial poll
  }, [getMessages, processConversationEntries, resetTimeout, stopPolling]);

  const startChat = useCallback(async () => {
    try {
      stopPolling();

      // Reset all state
      setMessages([]);
      setIsLoading(false);
      setIsTyping(false);
      setCurrentAgent(null);
      setError(null);
      processedMessageIdsRef.current.clear();

      const creds = await initialize();
      credsRef.current = creds;

      console.log("Chat initialized:", {
        conversationId: creds.conversationId,
      });

      startPolling();
      resetTimeout();
    } catch (err) {
      console.error("Chat initialization error:", err);
      setError("Failed to start chat");
      setIsConnected(false);
    }
  }, [initialize, startPolling, stopPolling, resetTimeout]);

  const sendMessage = async (content: string) => {
    if (!credsRef.current) return;
    resetTimeout();

    const message = {
      id: crypto.randomUUID(),
      type: "user" as const,
      content,
      timestamp: new Date(),
    };

    try {
      setMessages((prev) => [...prev, message]);
      setIsLoading(true);

      await sendMessageToApi(
        credsRef.current.accessToken,
        credsRef.current.conversationId,
        content
      );

      // Ensure polling is running after sending a message
      if (!pollingRef.current) {
        if (DEBUG) {
          console.log("[useChat] Polling was stopped, restarting...");
        }
        startPolling();
      }
    } catch (err) {
      console.error(err);
      setError("Failed to send message");
      setIsLoading(false);
      setMessages((prev) => prev.filter((m) => m.id !== message.id));
    }
  };

  const closeChat = async (onClosed: () => void) => {
    try {
      if (!credsRef.current) return;

      stopPolling();

      await closeChatApi(
        credsRef.current.accessToken,
        credsRef.current.conversationId
      );

      setIsConnected(false);
      setIsTyping(false);
      setCurrentAgent(null);
      setMessages([]);
      setIsLoading(false);
      setError(null);
      onClosed();
    } catch (err) {
      console.error("Failed to close chat:", err);
      setError("Failed to close chat");
    }
  };

  useEffect(() => {
    if (isInitializedRef.current) return;
    isInitializedRef.current = true;

    startChat();

    return () => {
      stopPolling();
      if (timeoutRef.current) clearTimeout(timeoutRef.current);
    };
  }, [startChat, stopPolling]);

  return {
    messages,
    isConnected,
    isLoading,
    isTyping,
    currentAgent,
    error,
    sendMessage,
    closeChat,
    startNewChat: startChat,
  };
}
