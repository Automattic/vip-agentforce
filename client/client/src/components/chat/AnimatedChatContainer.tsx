import React from "react";
import { motion, AnimatePresence } from "framer-motion";

interface AnimatedChatContainerProps {
  isOpen: boolean;
  isMobile: boolean;
  children: React.ReactNode;
}

export const AnimatedChatContainer: React.FC<AnimatedChatContainerProps> = ({
  isOpen,
  isMobile,
  children,
}) => {
  return (
    <AnimatePresence>
      {isOpen && (
        <motion.div
          key="chat-window"
          className="fixed z-50"
          initial={{ opacity: 0, scale: 0.95, y: 16 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.95, y: 16 }}
          transition={{ duration: 0.3, ease: "easeOut" }}
          style={
            isMobile
              ? { inset: 0 }
              : {
                  right: "1rem",
                  bottom: "1rem",
                  width: "400px",
                  height: "600px",
                }
          }
        >
          <div
            className={`
              bg-white flex flex-col
              ${
                isMobile
                  ? "w-full h-full"
                  : "rounded-xl shadow-2xl w-full h-full"
              }
            `}
          >
            {children}
          </div>
        </motion.div>
      )}
    </AnimatePresence>
  );
};
