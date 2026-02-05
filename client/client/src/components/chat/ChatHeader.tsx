import {
  Minimize2,
  X,
  MessageSquarePlus,
  MoreVertical,
  Sun,
  Moon,
} from "lucide-react";
import { Dropdown, DropdownItem, DropdownSeparator } from "../Dropdown";
import { useTheme, themeConfig } from "../../hooks";

interface ChatHeaderProps {
  agentName: string | null;
  agentRole: string;
  isConnected: boolean;
  onMinimize: () => void;
  onClose: () => void;
  onStartNewChat: () => void;
}

export const ChatHeader = ({
  agentName,
  agentRole,
  isConnected,
  onMinimize,
  onClose,
  onStartNewChat,
}: ChatHeaderProps) => {
  const { theme, toggleTheme } = useTheme();
  const styles = themeConfig[theme];
  const isMobile = window.innerWidth < 768;

  return (
    <div
      className={`${styles.primary} p-3 sm:p-4 flex justify-between items-center flex-shrink-0 transition-all duration-300 ${isMobile ? "" : "rounded-t-xl"}`}
    >
      <div>
        {agentName === null ? (
          <div className="space-y-2">
            {!isConnected && (
              <div className="h-6 bg-white bg-opacity-20 rounded w-40 animate-pulse"></div>
            )}
            {isConnected && (
              <h2
                className={`text-lg sm:text-xl font-serif ${styles.primaryText}`}
              >
                AI Assistant
              </h2>
            )}
            <p
              className={`text-xs sm:text-sm ${styles.primaryText} opacity-75`}
            >
              {isConnected ? "Online" : "Disconnected"}
            </p>
          </div>
        ) : (
          <>
            <h2
              className={`text-lg sm:text-xl font-serif ${styles.primaryText}`}
            >
              {agentName}
            </h2>
            <p
              className={`text-xs sm:text-sm ${styles.primaryText} opacity-75`}
            >
              {agentRole}
            </p>
          </>
        )}
      </div>
      <div className="flex items-center space-x-2">
        <button
          onClick={toggleTheme}
          className="p-2 hover:bg-white hover:bg-opacity-20 rounded-full transition-colors duration-200"
          aria-label={`Switch to ${theme === "dark" ? "light" : "dark"} mode`}
        >
          {theme === "dark" ? (
            <Sun className={`w-4 h-4 sm:w-5 sm:h-5 ${styles.primaryText}`} />
          ) : (
            <Moon className={`w-4 h-4 sm:w-5 sm:h-5 ${styles.primaryText}`} />
          )}
        </button>

        <button
          onClick={onMinimize}
          className="p-2 hover:bg-white hover:bg-opacity-20 rounded-full transition-colors duration-200"
          aria-label="Minimize chat"
        >
          <Minimize2
            className={`w-4 h-4 sm:w-5 sm:h-5 ${styles.primaryText}`}
          />
        </button>

        <Dropdown
          trigger={
            <button className="p-2 hover:bg-white hover:bg-opacity-20 rounded-full transition-colors duration-200">
              <MoreVertical
                className={`w-4 h-4 sm:w-5 sm:h-5 ${styles.primaryText}`}
              />
            </button>
          }
          align="right"
        >
          <DropdownItem onClick={onStartNewChat} disabled={isConnected}>
            <div className="flex items-center gap-2">
              <MessageSquarePlus className="w-4 h-4" />
              <span>Start New Chat</span>
            </div>
          </DropdownItem>

          <DropdownSeparator />

          <DropdownItem
            onClick={onClose}
            className="text-red-600 hover:bg-red-50"
          >
            <div className="flex items-center gap-2">
              <X className="w-4 h-4" />
              <span>End Chat</span>
            </div>
          </DropdownItem>
        </Dropdown>
      </div>
    </div>
  );
};
