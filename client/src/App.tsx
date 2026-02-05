import React, { useState } from "react";
import {
  ChatWindow,
  ChatMinimized,
  ChatErrorBoundary,
} from "./components/chat";
import { GitHubLink } from "./components/GitHubLink";
import ContentLayout from "./components/ContentLayout";
import ThemeProvider from "./contexts/ThemeContext";

const AppContent: React.FC = () => {
  const [isChatOpen, setIsChatOpen] = useState(false);

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="border-b bg-white px-4 sm:px-6 md:px-8">
        <div className="max-w-2xl mx-auto py-4 flex justify-between items-center">
          <h1 className="text-lg lg:text-2xl font-medium">
            Agentforce Custom Client Demo
          </h1>
          <GitHubLink href="https://github.com/charlesw-salesforce/agentforce-custom-client" />
        </div>
      </div>

      {/* Main Content */}
      <main className="px-4 py-8">
        <ContentLayout />
      </main>

      {/* Chat Widget */}
      {!isChatOpen && <ChatMinimized onMaximize={() => setIsChatOpen(true)} />}
      {isChatOpen && (
        <ChatErrorBoundary>
          <ChatWindow
            agentRole="AI Concierge"
            onClose={() => setIsChatOpen(false)}
          />
        </ChatErrorBoundary>
      )}
    </div>
  );
};

const App: React.FC = () => {
  return (
    <ThemeProvider>
      <AppContent />
    </ThemeProvider>
  );
};

export default App;
