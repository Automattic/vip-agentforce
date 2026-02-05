import React from "react";

interface Props {
  children: React.ReactNode;
}

interface State {
  hasError: boolean;
}

export class ChatErrorBoundary extends React.Component<Props, State> {
  state: State = {
    hasError: false
  };

  static getDerivedStateFromError(): State {
    return { hasError: true };
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="p-4 text-red-800 bg-red-50 rounded">
          <h3 className="font-medium">Something went wrong</h3>
          <button
            onClick={() => window.location.reload()}
            className="text-sm text-red-600 hover:text-red-800"
          >
            Refresh widget
          </button>
        </div>
      );
    }

    return this.props.children;
  }
}