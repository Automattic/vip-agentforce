import React from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { ThumbsUp, ThumbsDown, Info } from 'lucide-react';
import { Message } from '../../types';
import { useTheme, themeConfig } from '../../hooks';

export const ChatMessage: React.FC<Message> = ({ type, content, timestamp }) => {
  const { theme } = useTheme();
  const styles = themeConfig[theme];

  if (type === 'system') {
    return (
      <div className="flex justify-center my-2">
        <div className={`
          animate-fade-in animate-duration-300 
          flex items-center gap-2 px-3 py-1.5 
          rounded-full text-xs max-w-[85%] sm:max-w-md
          ${styles.messageBubble.system}
        `}>
          <Info className="w-3 h-3" />
          <span>{content}</span>
        </div>
      </div>
    );
  }

  return (
    <div className={`flex ${type === 'user' ? 'justify-end' : 'justify-start'}`}>
      <div className={`
        max-w-[85%] sm:max-w-md rounded-lg p-2 sm:p-3
        transition-all duration-300 ease-out
        ${type === 'user' ? styles.messageBubble.user : styles.messageBubble.ai}
      `}>
        <div className="prose-sm max-w-full text-sm break-words">
          <ReactMarkdown
            remarkPlugins={[remarkGfm]}
            components={{
              a: ({ ...props }) => (
                <a
                  {...props}
                  className={`
                    ${type === 'user'
                      ? 'text-emerald-200 hover:text-emerald-100'
                      : 'text-emerald-600 hover:text-emerald-800'
                    } underline
                  `}
                  target="_blank"
                  rel="noopener noreferrer"
                />
              ),
              code: ({ ...props }) => (
                <code
                  {...props}
                  className={`
                    ${type === 'user'
                      ? 'bg-opacity-20 bg-black text-current'
                      : 'bg-gray-100 text-gray-800'
                    } rounded px-1 py-0.5 text-xs
                  `}
                />
              ),
              pre: ({ ...props }) => (
                <pre
                  {...props}
                  className={`
                    ${type === 'user'
                      ? 'bg-opacity-20 bg-black text-current'
                      : 'bg-gray-100 text-gray-800'
                    } rounded p-2 text-xs overflow-x-auto
                  `}
                />
              )
            }}
          >
            {content}
          </ReactMarkdown>
        </div>
        <div className="mt-2 flex justify-end items-center space-x-2">
          <span className={`text-xs opacity-60`}>
            {timestamp.toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit'
            })}
          </span>
          {type === 'ai' && (
            <div className="flex space-x-1">
              <button className={`p-1 ${styles.secondaryHover} rounded-full`}>
                <ThumbsUp className="w-3 h-3 opacity-50" />
              </button>
              <button className={`p-1 ${styles.secondaryHover} rounded-full`}>
                <ThumbsDown className="w-3 h-3 opacity-50" />
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};