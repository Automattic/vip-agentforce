import { MessageCircle } from "lucide-react";
import { useTheme, themeConfig } from '../../hooks';

export const ChatMinimized = ({ onMaximize }: { onMaximize: () => void }) => {
  const { theme } = useTheme();
  const styles = themeConfig[theme];

  return (
    <div className="fixed bottom-6 right-6">
      <button
        onClick={onMaximize}
        className={`
          ${styles.primary} ${styles.primaryText}
          p-4 rounded-full shadow-md hover:shadow-lg
          transform transition-all duration-300
          hover:-translate-y-1 ${styles.primaryHover}
        `}
      >
        <MessageCircle className="w-6 h-6" />
      </button>
    </div>
  );
};
