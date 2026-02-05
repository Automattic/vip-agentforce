import React, { useState, useRef, useEffect } from "react";
import { ChevronDown } from "lucide-react";

interface CollapsibleProps {
  title: string;
  children: React.ReactNode;
  defaultOpen?: boolean;
  className?: string;
}

export const Collapsible: React.FC<CollapsibleProps> = ({
  title,
  children,
  defaultOpen = true,
  className = "",
}) => {
  const [isOpen, setIsOpen] = useState(defaultOpen);
  const contentRef = useRef<HTMLDivElement>(null);
  const [height, setHeight] = useState<number | undefined>(undefined);

  useEffect(() => {
    if (!contentRef.current) return;

    const updateHeight = (node: HTMLElement) => {
      setHeight(isOpen ? node.scrollHeight : 0);
    };

    try {
      const resizeObserver = new ResizeObserver(([entry]) => {
        if (entry && isOpen) {
          updateHeight(entry.target as HTMLElement);
        }
      });

      const currentNode = contentRef.current;
      resizeObserver.observe(currentNode);
      updateHeight(currentNode);

      return () => resizeObserver.disconnect();
    } catch (err) {
      console.error(err);
      updateHeight(contentRef.current);
    }
  }, [isOpen]);

  return (
    <div className={className}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-full flex items-center justify-between p-4 text-left font-medium hover:bg-gray-50 rounded-lg transition-colors duration-200 bg-white focus:outline-none focus:ring-2 focus:ring-gray-200"
        aria-expanded={isOpen}
      >
        <span>{title}</span>
        <ChevronDown
          className={`w-5 h-5 text-gray-500 transition-transform duration-200 ease-in-out ${
            isOpen ? "transform rotate-180" : ""
          }`}
        />
      </button>
      <div
        ref={contentRef}
        className="overflow-hidden transition-all duration-200 ease-in-out"
        style={{ height: height ? `${height}px` : "0px" }}
        aria-hidden={!isOpen}
      >
        <div className="p-4">{children}</div>
      </div>
    </div>
  );
};
