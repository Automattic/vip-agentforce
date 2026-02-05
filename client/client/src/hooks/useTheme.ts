import { createContext, useContext } from "react";
import { Theme, ThemeConfig } from "../types";

interface ThemeContextType {
  theme: Theme;
  toggleTheme: () => void;
}

export const ThemeContext = createContext<ThemeContextType | undefined>(
  undefined
);

export const useTheme = () => {
  const context = useContext(ThemeContext);
  if (context === undefined) {
    throw new Error("useTheme must be used within a ThemeProvider");
  }
  return context;
};

export const themeConfig: ThemeConfig = {
  dark: {
    primary: "bg-black",
    primaryHover: "hover:bg-gray-800",
    primaryText: "text-white",
    secondary: "bg-gray-100",
    secondaryHover: "hover:bg-gray-200",
    secondaryText: "text-gray-800",
    border: "border-gray-200",
    inputBg: "bg-gray-50",
    messageBubble: {
      user: "bg-black text-gray-300 border border-transparent",
      ai: "bg-gray-100 text-gray-800 border border-gray-200",
      system: "bg-slate-200 text-slate-700 border border-gray-200",
    },
  },
  light: {
    primary: "bg-teal-600",
    primaryHover: "hover:bg-teal-700",
    primaryText: "text-white",
    secondary: "bg-teal-50",
    secondaryHover: "hover:bg-teal-100",
    secondaryText: "text-teal-900",
    border: "border-teal-100",
    inputBg: "bg-white",
    messageBubble: {
      user: "bg-teal-600 text-white border border-teal-500",
      ai: "bg-gray-100 text-gray-800 border border-gray-200",
      system: "bg-slate-200 text-slate-700 border border-gray-200",
    },
  },
} as const;
