# Licence

**The Manager connector is free software, licensed under the MIT licence.**

Copyright (c) Coysh Digital.

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
associated documentation files (the "Software"), to deal in the Software without restriction,
including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense
and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following condition:

The above copyright notice and this permission notice shall be included in all copies or substantial
portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT
LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## Why MIT here, when the control plane is AGPL

This plugin gets installed inside somebody else's Craft installation, alongside their own code. A
copyleft licence in that position raises a question a customer should never have to put to their
lawyer before they can monitor their own websites. MIT removes the question entirely.

The control plane it talks to is [AGPL-3.0-or-later](https://github.com/Coysh-Digital/manager), and
the [protocol between them](https://github.com/Coysh-Digital/manager-protocol) is MIT, so the whole
wire contract can be read, verified or reimplemented by anyone.

## Before you install it

The source is published so it can be reviewed before it goes anywhere near a production website. It
is worth actually doing that: this plugin runs with your Craft installation's privileges. It makes
only outbound requests, it exposes no inbound management endpoint, and every capability it will act
on is granted explicitly. All three of those claims are readable in this repository rather than
asserted here.
