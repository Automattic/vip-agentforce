import React from 'react';
import { ExternalLink, BookOpen } from 'lucide-react';
import { FaGithub, FaYoutube } from 'react-icons/fa'
import { Collapsible } from './Collapsible';

const ResourceCard = ({
  icon: Icon,
  title,
  description,
  link,
  children
}: {
  icon: React.ElementType;
  title: string;
  description: string;
  link: string;
  children?: React.ReactNode;
}) => (
  <div
    className="block p-6 bg-white rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200"
    rel="noopener noreferrer"
  >
    <div className="flex items-start gap-4">
      <Icon className="w-5 h-5 text-gray-600 flex-shrink-0 mt-1" />
      <div>
        <a href={link}
          target="_blank">
          <h3 className="text-lg font-medium flex items-center gap-2">
            {title}
            <ExternalLink className="w-4 h-4 text-gray-400" />
          </h3>
        </a>
        <p className="mt-1 text-gray-600 text-sm">{description}</p>
        {children && <div className="mt-4">{children}</div>}
      </div>
    </div>
  </div>
);

const IMPLEMENTATION_FEATURES = [
  'Real-time messaging with Server-Sent Events',
  'Typing indicators and read receipts',
  'Seamless agent handoff',
  'React + TypeScript implementation'
];

const ContentLayout = () => {
  return (
    <div className="max-w-2xl mx-auto">
      {/* Introduction */}
      <p className="text-gray-600 mb-8">
        Experience a custom implementation of Salesforce's Messaging for In-App and Web APIs. This
        demo showcases a fully functional chat interface built with React and TypeScript.
      </p>

      <section className="bg-slate-50 border-l-4 border-slate-500 p-4 mb-8 rounded-r-lg">
        <h2 className="font-medium text-lg text-slate-900 mb-3">How to Use the Demo</h2>
        <ol className="list-decimal list-inside space-y-2 text-slate-800">
          <li>Click the chat bubble in the bottom right corner to open the chat window</li>
          <li>Wait for the AI agent to connect and greet you</li>
          <li>Type your message and press send or hit Enter</li>
          <li>Experience real-time messaging with simulated agent responses</li>
          <li>Use the minimize or close buttons to manage the chat window</li>
        </ol>
      </section>

      {/* Features */}
      <section className="mb-4">
        <Collapsible title="Implementation Features">
          <div className="space-y-3">
            {IMPLEMENTATION_FEATURES.map((feature, i) => (
              <div
                key={i}
                className="flex items-center gap-3 transition-all duration-200 ease-in-out hover:translate-x-1"
              >
                <svg
                  className="w-5 h-5 text-emerald-600 flex-shrink-0"
                  viewBox="0 0 20 20"
                  fill="currentColor"
                >
                  <path
                    fillRule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                    clipRule="evenodd"
                  />
                </svg>
                <span className="text-gray-700">{feature}</span>
              </div>
            ))}
          </div>
        </Collapsible>
      </section>

      {/* Resources */}
      <section className="space-y-4">
        <Collapsible title="Resources" defaultOpen={false}>
          <div className="space-y-4">
            <ResourceCard
              icon={FaYoutube}
              title="Setup Tutorial"
              description="Watch this comprehensive guide to configure and deploy an Agentforce Service Agent in your Salesforce org."
              link="https://youtube.com/https://www.youtube.com/live/1vuZfPEtuUM?si=WvJ2OTJF3txlezLG"
            >
              <div className="bg-blue-50 rounded p-4 text-sm">
                <div className="font-medium text-blue-900 mb-2">Quick Start:</div>
                <ol className="list-decimal list-inside space-y-1 text-blue-800">
                  <li>Follow the tutorial</li>
                  <li>Clone the repository</li>
                  <li>Follow the README</li>
                  <li>Start chatting!</li>
                </ol>
              </div>
            </ResourceCard>

            <ResourceCard
              icon={FaGithub}
              title="Source Code"
              description="Get the complete source code on GitHub. Includes implementation details and setup instructions."
              link="https://github.com/charlesw-salesforce/agentforce-custom-client"
            />

            <ResourceCard
              icon={BookOpen}
              title="Official Documentation"
              description="Explore Salesforce's comprehensive documentation on Messaging for In-App and Web APIs."
              link="https://help.salesforce.com/s/articleView?id=sf.miaw_deployment_custom.htm"
            />
          </div>
        </Collapsible>
      </section>
    </div>
  );
};

export default ContentLayout;