  
  ### 1. Point the AI directly to the Vault
  
  Start your prompt by telling the AI:
  
  Read the  00 AI START HERE.md  and  AI Memory/Active Session.md  files inside the  dol ngai  Obsidian folder to understand the codebase context.                                                                                   
  
  ### 2. Follow this step-by-step developer flow:
  
  1. Review the dev status: Open TODO.md to choose the next task, and consult Bugs.md to avoid repeating   
  past errors (like balance calculations drift).                                                         
  2. Consult Feature Specs: Look at the corresponding spec in the  Features/  folder to understand access rules, flows,
  and models associated with the area you are modifying.                                                                 
  3. Build bidirectionally:
      • If writing new logic, place it in  app/Services  and document the new public methods/algorithms in a markdown
      note under  Backend/Services/ .                                                                                    
      • If adding a DB column or table, create a note under  Database/  detailing the constraints and relationships.
  4. Link the notes: Always link new files using  [[Wiki Links]]  so the knowledge base stays fully connected.           
  5. Update the Handover: At the end of the session, update Active Session.md with what was completed and what remains to be done.