export interface SalesforceConfig {
  scrtUrl: string;
  orgId: string;
  esDeveloperName: string;
}

export interface MessagingHeaders {
  authorization: string;
  "x-conversation-id": string;
}

export interface BaseMessageRequest {
  Headers: MessagingHeaders;
}

export interface MessageRequest {
  Body: { message: string };
  Headers: MessagingHeaders;
}

export interface ServerSentEventRequest {
  Querystring: { token: string };
  Params: {};
  Headers: {};
  Body: {};
}

export interface TypingRequest {
  Body: { isTyping: boolean };
  Headers: MessagingHeaders;
}
