import React from "react";
import { Send, Mic } from "lucide-react";
import { useSpeechRecognition, useTheme, themeConfig } from "../../hooks";

interface ChatInputProps {
  value: string;
  onChange: (value: string) => void;
  onSend: () => void;
  onKeyPress: (e: React.KeyboardEvent<HTMLTextAreaElement>) => void;
  isEnabled: boolean;
  isConnecting?: boolean;
  onReconnect?: () => void;
}

export const ChatInput: React.FC<ChatInputProps> = ({
  value,
  onChange,
  onSend,
  onKeyPress,
  isEnabled,
  isConnecting = false,
  onReconnect,
}) => {
  const { theme } = useTheme();
  const styles = themeConfig[theme];

  const { isListening, isSupported, toggleListening } = useSpeechRecognition({
    onTranscript: onChange,
    onFinalTranscript: () => {
      if (value.trim()) {
        onSend();
      }
    },
  });

  return (
    <div className="border-t p-2 sm:p-3">
      <div className="flex flex-col sm:flex-row items-stretch sm:items-end space-y-2 sm:space-y-0 sm:space-x-3">
        <div
          className={`
          flex-1 ${styles.inputBg} rounded-lg border border-transparent 
          transition-all duration-200 
          ${isEnabled ? "hover:border-gray-200" : "opacity-50"}
        `}
        >
          <textarea
            value={value}
            onChange={(e) => onChange(e.target.value)}
            onKeyDown={onKeyPress}
            onFocus={() => {
              if (!isEnabled && onReconnect) {
                onReconnect();
              }
            }}
            placeholder={
              isConnecting
                ? "Waiting for agent..."
                : isEnabled
                  ? isListening
                    ? "Listening..."
                    : "Type your message..."
                  : "Click to reconnect..."
            }
            className="w-full p-3 bg-transparent resize-none focus:outline-none text-sm cursor-pointer"
            rows={1}
            style={{ minHeight: "44px", maxHeight: "200px" }}
            readOnly={!isEnabled}
            aria-label="Chat message input"
          />
          <div className="flex justify-between items-center px-2 sm:px-3 pb-2 sm:pb-3">
            <div>
              <button
                onClick={toggleListening}
                disabled={!isEnabled || !isSupported}
                className={`relative p-1 hover:bg-gray-200 rounded-full transition-all duration-300`}
                aria-label="Toggle speech recognition"
              >
                {isListening && (
                  <span className="absolute inset-0 flex items-center justify-center">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-50"></span>
                    <span className="relative inline-flex rounded-full h-full w-full bg-red-50"></span>
                  </span>
                )}
                <Mic
                  className={`relative w-4 h-4 ${isListening ? "text-red-500" : "text-gray-500"}`}
                />
              </button>
            </div>
            <button
              onClick={onSend}
              disabled={!value.trim() || !isEnabled}
              className={`p-3 rounded-full transition-colors duration-200 ${
                value.trim() && isEnabled
                  ? `${styles.primary} ${styles.primaryText} ${styles.primaryHover}`
                  : "bg-gray-100 text-gray-400"
              }`}
              aria-label="Send message"
            >
              <Send className="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};
