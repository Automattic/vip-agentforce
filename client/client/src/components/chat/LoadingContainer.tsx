import React from 'react';

type DotSize = 'small' | 'medium';
type Alignment = 'left' | 'right';

interface LoadingDotsProps {
  size?: DotSize;
}

interface LoadingContainerProps {
  align?: Alignment;
}

const dotSizes: Record<DotSize, string> = {
  small: 'w-2 h-2',
  medium: 'w-3 h-3'
};

const LoadingDots: React.FC<LoadingDotsProps> = ({ size = 'small' }) => {
  return (
    <div className="flex space-x-2">
      <div className={`${dotSizes[size]} bg-gray-400 rounded-full animate-bounce`} />
      <div
        className={`${dotSizes[size]} bg-gray-400 rounded-full animate-bounce`}
        style={{ animationDelay: '0.2s' }}
      />
      <div
        className={`${dotSizes[size]} bg-gray-400 rounded-full animate-bounce`}
        style={{ animationDelay: '0.4s' }}
      />
    </div>
  );
};

export const LoadingContainer: React.FC<LoadingContainerProps> = ({
  align = 'left'
}) => {
  return (
    <div
      className={`
        flex ${align === 'right' ? 'justify-end' : 'justify-start'}
      `}
    >
      <div className="bg-gray-200 rounded-lg p-3">
        <LoadingDots size='small' />
      </div>
    </div>
  );
};
