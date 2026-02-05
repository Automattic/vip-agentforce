import React, { useState, useRef, useEffect } from "react";
import {
  AnimatedChatContainer,
  ChatInput,
  ChatHeader,
  ChatMessageList,
  ChatMinimized,
} from "./index";
import { useChat } from "../../hooks";
import { useViewport } from "../../hooks/useViewport";

interface ChatWindowProps {
  onClose: () => void;
  agentRole: string;
}

type WindowState = "MAXIMIZED" | "MINIMIZED";

export const ChatWindow: React.FC<ChatWindowProps> = ({
  onClose,
  agentRole,
}) => {
  const [windowState, setWindowState] = useState<WindowState>("MAXIMIZED");
  const [inputValue, setInputValue] = useState("");
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const { isMobile } = useViewport();

  const {
    messages,
    isConnected,
    isLoading,
    isTyping,
    error,
    currentAgent,
    sendMessage,
    closeChat,
    startNewChat,
  } = useChat();

  useEffect(() => {
    if (windowState === "MAXIMIZED" && messagesEndRef.current) {
      const behavior = messages.length <= 1 ? "auto" : "smooth";
      messagesEndRef.current.scrollIntoView({ behavior, block: "end" });
    }
  }, [messages, windowState]);

  // Send messages
  const handleSend = async () => {
    if (!inputValue.trim() || !isConnected) return;
    const messageText = inputValue;
    setInputValue("");

    try {
      await sendMessage(messageText);
    } catch (error) {
      console.error("Failed to send message:", error);
      setInputValue(messageText);
    }
  };

  // Handle window state
  const handleMinimize = () => {
    setWindowState("MINIMIZED");
  };

  const handleMaximize = () => {
    setWindowState("MAXIMIZED");
  };

  const handleClose = async () => {
    try {
      setWindowState("MINIMIZED");
      await closeChat(onClose);
    } catch (error) {
      console.error("Error closing chat:", error);
      onClose();
    }
  };

  return (
    <>
      {windowState === "MINIMIZED" && (
        <div className="fixed right-4 bottom-4 z-50">
          <ChatMinimized onMaximize={handleMaximize} />
        </div>
      )}
      <AnimatedChatContainer
        isOpen={windowState === "MAXIMIZED"}
        isMobile={isMobile}
      >
        <ChatHeader
          agentName={currentAgent}
          agentRole={agentRole}
          onMinimize={handleMinimize}
          onClose={handleClose}
          isConnected={isConnected}
          onStartNewChat={startNewChat}
        />

        <ChatMessageList
          messages={messages}
          isLoading={isLoading || isTyping}
          error={!!error}
          messagesEndRef={messagesEndRef}
        />

        <ChatInput
          value={inputValue}
          onChange={setInputValue}
          onSend={handleSend}
          onKeyPress={(e) => {
            if (e.key === "Enter" && !e.shiftKey) {
              e.preventDefault();
              handleSend();
            }
          }}
          isEnabled={isConnected}
          onReconnect={startNewChat}
        />
      </AnimatedChatContainer>
    </>
  );
};
