import React from 'react';
import { FaGithub } from 'react-icons/fa';

interface GitHubLinkProps {
  href: string;
  className?: string;
}

export const GitHubLink: React.FC<GitHubLinkProps> = ({
  href,
  className = ''
}): JSX.Element => {
  return (
    <a
      href={href}
      target='_blank'
      rel="noopener noreferrer"
      className={`text-gray-700 hover:text-gray-900 transition-colors flex items-center ${className}`}
      aria-label="See this on GitHub"
    >
      <FaGithub className="w-5 h-5 lg:w-8 lg:h-8" />
    </a >
  );
};