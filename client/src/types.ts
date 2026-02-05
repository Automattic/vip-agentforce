export interface Message {
  id: string;
  type: "user" | "ai" | "system";
  content: string;
  timestamp: Date;
}

export interface Entry {
  operation: string;
  menuMetadata: object;
  participant: Participant;
  displayName: string;
}

export interface Participant {
  role: string;
  appType: string;
  subject: string;
  clientIdentifier: string;
}

export type Theme = "dark" | "light";

interface ThemeStyles {
  primary: string;
  primaryHover: string;
  primaryText: string;
  secondary: string;
  secondaryHover: string;
  secondaryText: string;
  border: string;
  inputBg: string;
  messageBubble: {
    user: string;
    ai: string;
    system: string;
  };
}

export interface ThemeConfig {
  dark: ThemeStyles;
  light: ThemeStyles;
}
